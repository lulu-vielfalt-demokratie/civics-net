<?php

namespace Drupal\platformsync\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\platformsync\Service\ModelService;
use Drupal\platformsync\Service\OAuthService;
use Drupal\platformsync\Service\UsageService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Psr\Log\LoggerInterface;

/**
 * External PlatformSync generation endpoint: POST /api/platformsync/generate
 *
 * Requires Bearer token from /api/platformsync/oauth/token
 *
 * @RestResource(
 *   id = "platformsync_generate",
 *   label = @Translation("PlatformSync Generate"),
 *   uri_paths = {
 *     "create" = "/api/platformsync/generate"
 *   }
 * )
 */
class PlatformSyncGenerateResource extends ResourceBase {

  protected ModelService $anthropic;
  protected OAuthService $oauth;
  protected UsageService $usage;

  public function __construct(
    array $configuration, $plugin_id, $plugin_definition,
    array $serializer_formats, LoggerInterface $logger,
    ModelService $anthropic, OAuthService $oauth, UsageService $usage
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->anthropic = $anthropic;
    $this->oauth     = $oauth;
    $this->usage     = $usage;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.channel.platformsync'),
      $container->get('platformsync.anthropic'),
      $container->get('platformsync.oauth'),
      $container->get('platformsync.usage')
    );
  }

  /**
   * POST /api/platformsync/generate
   *
   * Headers: Authorization: Bearer {token}
   * Body: {
   *   "text": "...",
   *   "platforms": ["bluesky","mastodon",...],
   *   "tone": "informativ",
   *   "context": "optional campaign context"
   * }
   */
  public function post(array $data, Request $request): ResourceResponse {
    // Authenticate via Bearer token.
    $authHeader = $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
      throw new AccessDeniedHttpException('Bearer token required.');
    }
    $keyRow = $this->oauth->validateToken($m[1]);
    if (!$keyRow) {
      throw new AccessDeniedHttpException('Invalid or expired token.');
    }

    // Validate input.
    $text      = trim($data['text'] ?? '');
    $platforms = $data['platforms'] ?? [];
    $tone      = $data['tone'] ?? 'informativ';
    $context   = $data['context'] ?? '';

    if (empty($text)) {
      throw new BadRequestHttpException('Field "text" is required.');
    }
    if (empty($platforms) || !is_array($platforms)) {
      throw new BadRequestHttpException('Field "platforms" must be a non-empty array.');
    }

    $validPlatforms = ['bluesky','mastodon','threads','instagram','telegram','whatsapp','signal','twitter','linkedin'];
    $platforms = array_values(array_intersect($platforms, $validPlatforms));
    if (empty($platforms)) {
      throw new BadRequestHttpException('No valid platforms specified.');
    }

    // Credit check.
    $cost = 1;
    if (!$this->oauth->hasCredits($keyRow, $cost)) {
      throw new TooManyRequestsHttpException(null, 'Insufficient credits.');
    }

    // Generate.
$plan = $keyRow->plan ?? 'free';
    $result = $this->anthropic->generate($text, $platforms, $tone, $context, [], $plan);

    // Log and deduct.
    $outputChars = array_sum(array_map('strlen', $result['posts']));
    $status      = $result['error'] ? 'error' : 'success';

    $this->usage->log([
      'uid'          => $keyRow->uid,
      'kid'          => $keyRow->kid,
      'source'       => 'api',
      'platforms'    => implode(',', $platforms),
      'tone'         => $tone,
      'input_chars'  => strlen($text),
      'output_chars' => $outputChars,
      'tokens_used'  => $result['tokens_used'],
      'credits_cost' => $cost,
      'status'       => $status,
      'error_msg'    => $result['error'] ?? '',
    ]);

    if ($status === 'success') {
      $this->oauth->deductCredits($keyRow->kid, $cost);
    }

    if ($result['error']) {
      return new ResourceResponse(['error' => $result['error']], 502);
    }

    return new ResourceResponse([
      'posts'       => $result['posts'],
      'tokens_used' => $result['tokens_used'],
      'credits_remaining' => $keyRow->credits - $keyRow->credits_used - $cost,
    ], 200);
  }

}
