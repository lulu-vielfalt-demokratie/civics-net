<?php

namespace Drupal\platformsync\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\platformsync\Service\OAuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Psr\Log\LoggerInterface;

/**
 * OAuth2 token endpoint: POST /api/platformsync/oauth/token
 *
 * @RestResource(
 *   id = "platformsync_oauth_token",
 *   label = @Translation("PlatformSync OAuth2 Token"),
 *   uri_paths = {
 *     "create" = "/api/platformsync/oauth/token"
 *   }
 * )
 */
class OAuthTokenResource extends ResourceBase {

  protected OAuthService $oauthService;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    OAuthService $oauthService
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->oauthService = $oauthService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.channel.platformsync'),
      $container->get('platformsync.oauth')
    );
  }

  /**
   * POST /api/platformsync/oauth/token
   *
   * Body: { "grant_type": "client_credentials", "client_id": "...", "client_secret": "..." }
   */
  public function post(array $data, Request $request): ResourceResponse {
    $grantType    = $data['grant_type'] ?? '';
    $clientId     = $data['client_id'] ?? '';
    $clientSecret = $data['client_secret'] ?? '';

    if ($grantType !== 'client_credentials') {
      throw new BadRequestHttpException('Only grant_type=client_credentials is supported.');
    }
    if (empty($clientId) || empty($clientSecret)) {
      throw new BadRequestHttpException('client_id and client_secret are required.');
    }

    try {
      $config     = \Drupal::config('platformsync.settings');
      $expiresIn  = (int) ($config->get('token_expiry_seconds') ?: 3600);
      $tokenData  = $this->oauthService->issueToken($clientId, $clientSecret, $expiresIn);
      return new ResourceResponse($tokenData, 200);
    }
    catch (\RuntimeException $e) {
      return new ResourceResponse(['error' => 'invalid_client', 'error_description' => $e->getMessage()], 401);
    }
  }

}
