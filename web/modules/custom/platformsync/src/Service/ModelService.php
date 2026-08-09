<?php

namespace Drupal\platformsync\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Unified model service — switches between Anthropic API and local Ollama.
 *
 * Plan-based routing (automatic):
 *   free       → Ollama (local, cost-free)
 *   pro        → Anthropic Claude (better quality)
 *   enterprise → Anthropic Claude (best quality)
 *
 * Manual override via config: platformsync.settings → model_provider:
 *   'plan'      — automatic plan-based routing (default)
 *   'anthropic' — always use Anthropic
 *   'ollama'    — always use Ollama
 *   'auto'      — Ollama if available, Anthropic as fallback
 */
class ModelService {

  const ANTHROPIC_URL  = 'https://api.anthropic.com/v1/messages';
  const ANTHROPIC_VER  = '2023-06-01';
  const OLLAMA_DEFAULT = 'http://127.0.0.1:11434';

  // Plans that use Anthropic API (paid plans)
  const PAID_PLANS = ['pro', 'enterprise'];

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger
  ) {
    $this->httpClient    = $httpClient;
    $this->configFactory = $configFactory;
    $this->logger        = $logger;
  }

  /**
   * Generate platform-specific posts.
   *
   * @param string $plan  User/API-key plan: 'free', 'pro', 'enterprise'
   * @return array ['posts' => [...], 'tokens_used' => int, 'provider' => string, 'error' => string|null]
   */
  public function generate(
    string $inputText,
    array $platforms,
    string $tone = 'informativ',
    string $campaignCtx = '',
    array $fewShotExamples = [],
    string $plan = 'free'
  ): array {

    $config   = $this->configFactory->get('platformsync.settings');
    $provider = $config->get('model_provider') ?: 'plan';

    $prompt = $this->buildPrompt($inputText, $platforms, $tone, $campaignCtx, $fewShotExamples);

    switch ($provider) {
      case 'ollama':
        return $this->generateOllama($prompt, $config);

      case 'auto':
        $result = $this->generateOllama($prompt, $config);
        if ($result['error']) {
          $this->logger->warning('Ollama unavailable, falling back to Anthropic.');
          return $this->generateAnthropic($prompt, $config);
        }
        return $result;

      case 'anthropic':
        return $this->generateAnthropic($prompt, $config);

      case 'plan':
      default:
        // Free → Ollama (kostenlos), Pro/Enterprise → Anthropic
        if (in_array($plan, self::PAID_PLANS)) {
          $this->logger->info('Plan @plan: using Anthropic API.', ['@plan' => $plan]);
          return $this->generateAnthropic($prompt, $config);
        }
        // Free plan: try Ollama, fall back to Anthropic if unavailable
        $result = $this->generateOllama($prompt, $config);
        if ($result['error']) {
          $this->logger->warning('Ollama unavailable for free plan, falling back to Anthropic.');
          return $this->generateAnthropic($prompt, $config);
        }
        return $result;
    }
  }

  /**
   * Generate via Anthropic Claude API.
   */
  protected function generateAnthropic(string $prompt, $config): array {
    $apiKey = $config->get('anthropic_api_key');
    $model  = $config->get('anthropic_model') ?: 'claude-sonnet-4-20250514';

    if (empty($apiKey)) {
      return $this->errorResult('Anthropic API key not configured.', 'anthropic');
    }

    try {
      $response = $this->httpClient->post(self::ANTHROPIC_URL, [
        'headers' => [
          'x-api-key'         => $apiKey,
          'anthropic-version' => self::ANTHROPIC_VER,
          'content-type'      => 'application/json',
        ],
        'json' => [
          'model'      => $model,
          'max_tokens' => 2048,
          'messages'   => [['role' => 'user', 'content' => $prompt]],
        ],
        'timeout' => 60,
      ]);

      $body       = json_decode($response->getBody()->getContents(), TRUE);
      $rawText    = $body['content'][0]['text'] ?? '';
      $tokens     = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);
      $posts      = $this->parseJson($rawText);

      return ['posts' => $posts, 'tokens_used' => $tokens, 'provider' => 'anthropic', 'error' => null];
    }
    catch (RequestException $e) {
      $this->logger->error('Anthropic API error: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage(), 'anthropic');
    }
  }

  /**
   * Generate via local Ollama instance.
   */
  protected function generateOllama(string $prompt, $config): array {
    $ollamaUrl = rtrim($config->get('ollama_url') ?: self::OLLAMA_DEFAULT, '/');
    $model     = $config->get('ollama_model') ?: 'llama3.2:3b';

    try {
      $response = $this->httpClient->post($ollamaUrl . '/api/generate', [
        'json' => [
          'model'  => $model,
          'prompt' => $prompt,
          'stream' => FALSE,
          'options' => [
            'temperature' => 0.7,
            'num_predict' => 2048,
          ],
        ],
        'timeout' => 120,  // lokal kann langsamer sein
      ]);

      $body    = json_decode($response->getBody()->getContents(), TRUE);
      $rawText = $body['response'] ?? '';
      // Ollama gibt keine Token-Counts wie Anthropic — schätzen
      $tokens  = (int) ($body['eval_count'] ?? 0) + (int) ($body['prompt_eval_count'] ?? 0);
      $posts   = $this->parseJson($rawText);

      return ['posts' => $posts, 'tokens_used' => $tokens, 'provider' => 'ollama', 'error' => null];
    }
    catch (RequestException $e) {
      $this->logger->error('Ollama API error: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage(), 'ollama');
    }
  }

  /**
   * Check if Ollama is running and the configured model is available.
   */
  public function ollamaStatus(): array {
    $config    = $this->configFactory->get('platformsync.settings');
    $ollamaUrl = rtrim($config->get('ollama_url') ?: self::OLLAMA_DEFAULT, '/');
    $model     = $config->get('ollama_model') ?: 'llama3.2:3b';

    try {
      $response = $this->httpClient->get($ollamaUrl . '/api/tags', ['timeout' => 5]);
      $body     = json_decode($response->getBody()->getContents(), TRUE);
      $models   = array_column($body['models'] ?? [], 'name');
      $available = in_array($model, $models);
      return [
        'running'   => TRUE,
        'model'     => $model,
        'available' => $available,
        'models'    => $models,
      ];
    }
    catch (\Exception $e) {
      return ['running' => FALSE, 'model' => $model, 'available' => FALSE, 'models' => []];
    }
  }

  /**
   * Build the generation prompt — shared between providers.
   * Supports few-shot examples for ML-optimized prompting.
   */
  protected function buildPrompt(
    string $inputText,
    array $platforms,
    string $tone,
    string $campaignCtx,
    array $fewShotExamples = []
  ): string {

    $config      = $this->configFactory->get('platformsync.settings');
    $context     = $campaignCtx ?: ($config->get('default_campaign_context') ?: '');
    $contextLine = $context ? "Kontext: $context\n\n" : '';

    $descriptions  = $this->getPlatformDescriptions();
    $selected      = array_filter($descriptions, fn($k) => in_array($k, $platforms), ARRAY_FILTER_USE_KEY);
    $platformList  = implode("\n", array_map(fn($k, $v) => "- $k: $v", array_keys($selected), $selected));

    // Few-Shot-Beispiele aus ML-Feedback einfügen
    $fewShotBlock = '';
    if (!empty($fewShotExamples)) {
      $fewShotBlock = "\nErfolgreiche Beispiel-Posts (zur Orientierung):\n";
      foreach ($fewShotExamples as $ex) {
        $fewShotBlock .= "- [{$ex['platform']}] Score {$ex['score']}: {$ex['text']}\n";
      }
      $fewShotBlock .= "\n";
    }

    return <<<PROMPT
Du bist ein erfahrener Social-Media-Redakteur.
{$contextLine}{$fewShotBlock}Erstelle aus dem folgenden Ausgangstext plattformspezifische Posts. Ton: {$tone}.

Ausgangstext:
"""
{$inputText}
"""

Erstelle Posts für diese Plattformen:
{$platformList}

Antworte NUR mit einem JSON-Objekt ohne Markdown-Backticks. Keys sind die Plattform-IDs, Values die fertigen Post-Texte.
PROMPT;
  }

  /**
   * Parse JSON from model output — handles markdown fences and whitespace.
   */
  protected function parseJson(string $raw): array {
    $clean = preg_replace('/^```json|^```|```$/m', '', trim($raw));
    // Manchmal gibt Ollama Text vor dem JSON aus — JSON-Block extrahieren
    if (preg_match('/\{.*\}/s', $clean, $matches)) {
      $clean = $matches[0];
    }
    return json_decode($clean, TRUE) ?? [];
  }

  protected function errorResult(string $msg, string $provider): array {
    return ['posts' => [], 'tokens_used' => 0, 'provider' => $provider, 'error' => $msg];
  }

  protected function getPlatformDescriptions(): array {
    return [
      'bluesky'   => 'Max. 300 Zeichen, 1–3 Hashtags, direkt und prägnant, kein Markdown.',
      'eurosky'   => 'Max. 300 Zeichen, 1–3 Hashtags, direkt und prägnant, kein Markdown. AT Protocol wie Bluesky.',
      'mastodon'  => 'Max. 500 Zeichen, Fediverse-Gemeinschaft, 2–4 Hashtags.',
      'threads'   => 'Max. 500 Zeichen, gesprächig, wenig Hashtags.',
      'instagram' => 'Bis 2200 Zeichen, emotional, viele thematische Hashtags am Ende, Emojis erlaubt.',
      'telegram'  => 'Channel-Ankündigung, **fett** und _kursiv_ Markdown erlaubt, informativ.',
      'whatsapp'  => 'Broadcast-Channel, kurz und direkt, kein Markdown, max. 2 Absätze.',
      'signal'    => 'Channel-Nachricht, noch knapper als WhatsApp, schlicht.',
      'twitter'   => 'Max. 280 Zeichen, 1–2 Hashtags, direkter Hook.',
      'linkedin'  => 'Professionell, 150–400 Wörter, 3–5 Hashtags am Ende.',
    ];
  }

}
