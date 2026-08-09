<?php

namespace Drupal\platformsync\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\platformsync\Service\ModelService;
use Drupal\platformsync\Service\OAuthService;
use Drupal\platformsync\Service\UsageService;
use Drupal\platformsync\Service\ChannelService;
use Drupal\platformsync\Service\PostingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Psr\Log\LoggerInterface;

/**
 * Publish endpoint: generates AND posts directly to configured channels.
 *
 * POST /api/platformsync/publish
 * Authorization: Bearer {token}
 *
 * Body:
 * {
 *   "text": "...",
 *   "platforms": ["mastodon", "bluesky"],
 *   "tone": "informativ",
 *   "context": "optional"
 * }
 *
 * Response:
 * {
 *   "results": {
 *     "mastodon": {
 *       "generated": "...",
 *       "published": true,
 *       "post_url": "https://...",
 *       "error": null
 *     },
 *     ...
 *   },
 *   "tokens_used": 412,
 *   "credits_remaining": 87
 * }
 *
 * @RestResource(
 *   id = "platformsync_publish",
 *   label = @Translation("PlatformSync Publish"),
 *   uri_paths = {
 *     "create" = "/api/platformsync/publish"
 *   }
 * )
 */
class PlatformSyncPublishResource extends ResourceBase {

  protected ModelService $model;
  protected OAuthService $oauth;
  protected UsageService $usage;
  protected ChannelService $channels;
  protected PostingService $posting;

  public function __construct(
    array $configuration, $plugin_id, $plugin_definition,
    array $serializer_formats, LoggerInterface $logger,
    ModelService $model, OAuthService $oauth, UsageService $usage,
    ChannelService $channels, PostingService $posting
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->model    = $model;
    $this->oauth    = $oauth;
    $this->usage    = $usage;
    $this->channels = $channels;
    $this->posting  = $posting;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.channel.platformsync'),
      $container->get('platformsync.model'),
      $container->get('platformsync.oauth'),
      $container->get('platformsync.usage'),
      $container->get('platformsync.channel'),
      $container->get('platformsync.posting')
    );
  }

  /**
   * POST /api/platformsync/publish
   */
  public function post(array $data, Request $request): ResourceResponse {

    // ── Auth ─────────────────────────────────────────────────────────────────
    $authHeader = $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
      throw new AccessDeniedHttpException('Bearer token required.');
    }
    $keyRow = $this->oauth->validateToken($m[1]);
    if (!$keyRow) {
      throw new AccessDeniedHttpException('Invalid or expired token.');
    }

    // ── Input validation ──────────────────────────────────────────────────────
    $text      = trim($data['text'] ?? '');
    $platforms = $data['platforms'] ?? [];
    $tone      = $data['tone'] ?? 'informativ';
    $context   = $data['context'] ?? '';
    $cardUrl   = $data['url'] ?? '';
    $cardUrl   = $data['url'] ?? '';

    if (empty($text)) {
      throw new BadRequestHttpException('Field "text" is required.');
    }

    $validPlatforms = ['bluesky','mastodon','threads','instagram','telegram','whatsapp','signal','twitter','linkedin','eurosky'];

    // platforms ist optional — wenn nicht angegeben, alle verifizierten Kanäle nutzen
    if (empty($platforms)) {
      $uid = (int) $keyRow->uid;
      $allChannels = $this->channels->getUserChannels($uid);
      $platforms = array_values(array_unique(array_map(fn($ch) => $ch->platform, $allChannels)));
    }
    else {
      $platforms = array_values(array_intersect($platforms, $validPlatforms));
    }

    if (empty($platforms)) {
      throw new BadRequestHttpException('No platforms specified and no verified channels configured.');
    }

    // ── Credit check ──────────────────────────────────────────────────────────
    $cost = 1;
    if (!$this->oauth->hasCredits($keyRow, $cost)) {
      throw new TooManyRequestsHttpException(null, 'Insufficient credits.');
    }

    // ── Get configured channels for this API key owner ────────────────────────
    $uid      = (int) $keyRow->uid;
    $channels = $this->channels->getUserChannels($uid);

    // Index channels by platform
    $channelsByPlatform = [];
    foreach ($channels as $ch) {
      if ($ch->verified && $ch->active) {
        $channelsByPlatform[$ch->platform] = $ch;
      }
    }

    // Only generate for platforms that have a configured channel
    $publishablePlatforms = array_filter(
      $platforms,
      fn($p) => isset($channelsByPlatform[$p])
    );

    $skippedPlatforms = array_diff($platforms, $publishablePlatforms);

    if (empty($publishablePlatforms)) {
      return new ResourceResponse([
        'error'   => 'No verified channels found for the requested platforms.',
        'skipped' => $skippedPlatforms,
        'hint'    => 'Configure channels at https://platformsync.de/platformsync/channels',
      ], 422);
    }

    // ── Generate posts ────────────────────────────────────────────────────────
$plan = $keyRow->plan ?? 'free';
    $result = $this->model->generate($text, array_values($publishablePlatforms), $tone, $context, [], $plan);

    $outputChars = array_sum(array_map('strlen', $result['posts']));
    $status      = $result['error'] ? 'error' : 'success';

    $this->usage->log([
      'uid'          => $uid,
      'kid'          => $keyRow->kid,
      'source'       => 'api_publish',
      'platforms'    => implode(',', $publishablePlatforms),
      'tone'         => $tone,
      'input_chars'  => strlen($text),
      'output_chars' => $outputChars,
      'tokens_used'  => $result['tokens_used'],
      'credits_cost' => $cost,
      'status'       => $status,
      'error_msg'    => $result['error'] ?? '',
    ]);

    if ($result['error']) {
      return new ResourceResponse(['error' => $result['error']], 502);
    }

    $this->oauth->deductCredits($keyRow->kid, $cost);

    // ── Publish to each channel ───────────────────────────────────────────────
    $results = [];

    foreach ($result['posts'] as $platform => $postText) {
      $ch = $channelsByPlatform[$platform] ?? NULL;
      if (!$ch) {
        $results[$platform] = [
          'generated'  => $postText,
          'published'  => FALSE,
          'post_url'   => NULL,
          'error'      => 'No verified channel configured.',
        ];
        continue;
      }

      $postResult = $this->posting->post((int) $ch->cid, $postText, $cardUrl);

      // Update last_used timestamp
      \Drupal::database()->update('platformsync_channels')
        ->fields(['last_used' => \Drupal::time()->getCurrentTime()])
        ->condition('cid', $ch->cid)
        ->execute();

      $results[$platform] = [
        'generated'  => $postText,
        'published'  => $postResult['success'],
        'post_id'    => $postResult['post_id'] ?? NULL,
        'post_url'   => $postResult['post_url'] ?? NULL,
        'error'      => $postResult['error'] ?? NULL,
      ];
    }

    // Add skipped platforms to response
    foreach ($skippedPlatforms as $p) {
      $results[$p] = [
        'generated'  => NULL,
        'published'  => FALSE,
        'post_url'   => NULL,
        'error'      => 'No verified channel configured for this platform.',
      ];
    }

    return new ResourceResponse([
      'results'           => $results,
      'tokens_used'       => $result['tokens_used'],
      'credits_remaining' => $keyRow->credits - $keyRow->credits_used - $cost,
    ], 200);
  }

}
