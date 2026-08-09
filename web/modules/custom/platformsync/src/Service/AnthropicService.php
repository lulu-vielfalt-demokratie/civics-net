<?php

namespace Drupal\platformsync\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * Communicates with the Anthropic Claude API.
 */
class AnthropicService {

  const API_URL = 'https://api.anthropic.com/v1/messages';
  const API_VERSION = '2023-06-01';

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
   * Generate PlatformSync content for multiple platforms.
   *
   * @param string $inputText     Source text to adapt.
   * @param array  $platforms     List of platform IDs.
   * @param string $tone          Tone identifier.
   * @param string $campaignCtx   Optional campaign context override.
   *
   * @return array ['posts' => [...], 'tokens_used' => int, 'error' => string|null]
   */
  public function generate(
    string $inputText,
    array $platforms,
    string $tone = 'informativ',
    string $campaignCtx = ''
  ): array {

    $config  = $this->configFactory->get('platformsync.settings');
    $apiKey  = $config->get('anthropic_api_key');
    $model   = $config->get('anthropic_model') ?: 'claude-sonnet-4-20250514';
    $context = $campaignCtx ?: ($config->get('default_campaign_context') ?: '');

    if (empty($apiKey)) {
      return ['posts' => [], 'tokens_used' => 0, 'error' => 'Anthropic API key not configured.'];
    }

    $platformDescriptions = $this->getPlatformDescriptions();
    $selectedDescriptions = array_filter(
      $platformDescriptions,
      fn($k) => in_array($k, $platforms),
      ARRAY_FILTER_USE_KEY
    );

    $platformList = implode("\n", array_map(
      fn($k, $v) => "- $k: $v",
      array_keys($selectedDescriptions),
      $selectedDescriptions
    ));

    $contextLine = $context ? "Kontext: $context\n\n" : '';
    $prompt = <<<PROMPT
Du bist ein erfahrener Social-Media-Redakteur.
{$contextLine}Erstelle aus dem folgenden Ausgangstext plattformspezifische Posts. Ton: {$tone}.

Ausgangstext:
"""
{$inputText}
"""

Erstelle Posts für diese Plattformen:
{$platformList}

Antworte NUR mit einem JSON-Objekt ohne Markdown-Backticks. Keys sind die Plattform-IDs, Values die fertigen Post-Texte.
PROMPT;

    try {
      $response = $this->httpClient->post(self::API_URL, [
        'headers' => [
          'x-api-key'         => $apiKey,
          'anthropic-version' => self::API_VERSION,
          'content-type'      => 'application/json',
        ],
        'json' => [
          'model'      => $model,
          'max_tokens' => 2048,
          'messages'   => [['role' => 'user', 'content' => $prompt]],
        ],
        'timeout' => 60,
      ]);

      $body        = json_decode($response->getBody()->getContents(), TRUE);
      $rawText     = $body['content'][0]['text'] ?? '';
      $tokensUsed  = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);
      $clean       = preg_replace('/^```json|```$/m', '', trim($rawText));
      $posts       = json_decode($clean, TRUE) ?? [];

      return ['posts' => $posts, 'tokens_used' => $tokensUsed, 'error' => null];
    }
    catch (RequestException $e) {
      $msg = $e->getMessage();
      $this->logger->error('Anthropic API request failed: @msg', ['@msg' => $msg]);
      return ['posts' => [], 'tokens_used' => 0, 'error' => $msg];
    }
  }

  /**
   * Platform descriptions for the prompt.
   */
  protected function getPlatformDescriptions(): array {
    return [
      'bluesky'   => 'Max. 300 Zeichen, 1–3 Hashtags, direkt und prägnant, kein Markdown.',
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
