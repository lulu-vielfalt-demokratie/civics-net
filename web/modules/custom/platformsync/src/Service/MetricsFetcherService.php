<?php

namespace Drupal\platformsync\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches engagement metrics from social media platform APIs.
 *
 * Supported: Mastodon, Bluesky
 * Planned:   LinkedIn, Telegram
 */
class MetricsFetcherService {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected FeedbackService $feedback;
  protected LoggerInterface $logger;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    FeedbackService $feedback,
    LoggerInterface $logger
  ) {
    $this->httpClient    = $httpClient;
    $this->configFactory = $configFactory;
    $this->feedback      = $feedback;
    $this->logger        = $logger;
  }

  /**
   * Process all pending feedback entries — called by cron.
   */
  public function processPending(): int {
    $pending = $this->feedback->getPendingMetricsFetch(24);
    $processed = 0;

    foreach ($pending as $entry) {
      $metrics = $this->fetchMetrics($entry->platform, $entry->post_url);
      if ($metrics !== NULL) {
        $this->feedback->updateMetrics($entry->fid, $metrics);
        $processed++;
      }
    }

    // 72h nochmal fetchen für bessere Datenqualität
    $pending72 = $this->feedback->getPendingMetricsFetch(72);
    foreach ($pending72 as $entry) {
      if ($entry->metrics_fetched_at && 
          ($entry->metrics_fetched_at < time() - 48 * 3600)) {
        $metrics = $this->fetchMetrics($entry->platform, $entry->post_url);
        if ($metrics !== NULL) {
          $this->feedback->updateMetrics($entry->fid, $metrics);
          $processed++;
        }
      }
    }

    return $processed;
  }

  /**
   * Route to correct fetcher by platform.
   */
  public function fetchMetrics(string $platform, string $url): ?array {
    switch ($platform) {
      case 'mastodon':
        return $this->fetchMastodon($url);
      case 'bluesky':
        return $this->fetchBluesky($url);
      default:
        return NULL;
    }
  }

  /**
   * Fetch Mastodon post metrics.
   *
   * URL format: https://mastodon.social/@user/123456789
   */
  protected function fetchMastodon(string $url): ?array {
    // Extract instance and status ID from URL
    if (!preg_match('#^https://([^/]+)/@[^/]+/(\d+)$#', $url, $m)) {
      $this->logger->warning('Invalid Mastodon URL: @url', ['@url' => $url]);
      return NULL;
    }
    $instance = $m[1];
    $statusId = $m[2];

    $config  = $this->configFactory->get('platformsync.settings');
    $token   = $config->get("mastodon_token_{$instance}") ?: $config->get('mastodon_token');

    $headers = ['Accept' => 'application/json'];
    if ($token) {
      $headers['Authorization'] = "Bearer $token";
    }

    try {
      $response = $this->httpClient->get(
        "https://{$instance}/api/v1/statuses/{$statusId}",
        ['headers' => $headers, 'timeout' => 10]
      );
      $data = json_decode($response->getBody()->getContents(), TRUE);

      return [
        'likes'   => (int) ($data['favourites_count'] ?? 0),
        'boosts'  => (int) ($data['reblogs_count'] ?? 0),
        'replies' => (int) ($data['replies_count'] ?? 0),
        'reach'   => max(1, (int) ($data['reblogs_count'] ?? 0) + (int) ($data['favourites_count'] ?? 0) + 1),
        'raw'     => $data,
      ];
    }
    catch (\Exception $e) {
      $this->logger->warning('Mastodon metrics fetch failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Fetch Bluesky post metrics via AT Protocol.
   *
   * URL format: https://bsky.app/profile/user.bsky.social/post/abc123
   */
  protected function fetchBluesky(string $url): ?array {
    if (!preg_match('#bsky\.app/profile/([^/]+)/post/([^/?]+)#', $url, $m)) {
      $this->logger->warning('Invalid Bluesky URL: @url', ['@url' => $url]);
      return NULL;
    }
    $handle = $m[1];
    $rkey   = $m[2];
    $uri    = "at://{$handle}/app.bsky.feed.post/{$rkey}";

    $config   = $this->configFactory->get('platformsync.settings');
    $identifier = $config->get('bluesky_identifier');
    $password   = $config->get('bluesky_app_password');

    try {
      // Auth
      $token = NULL;
      if ($identifier && $password) {
        $authResp = $this->httpClient->post('https://bsky.social/xrpc/com.atproto.server.createSession', [
          'json'    => ['identifier' => $identifier, 'password' => $password],
          'timeout' => 10,
        ]);
        $authData = json_decode($authResp->getBody()->getContents(), TRUE);
        $token    = $authData['accessJwt'] ?? NULL;
      }

      $headers = ['Accept' => 'application/json'];
      if ($token) {
        $headers['Authorization'] = "Bearer $token";
      }

      $response = $this->httpClient->get(
        'https://bsky.social/xrpc/app.bsky.feed.getPostThread',
        ['query' => ['uri' => $uri], 'headers' => $headers, 'timeout' => 10]
      );
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $post = $data['thread']['post'] ?? [];

      return [
        'likes'   => (int) ($post['likeCount'] ?? 0),
        'boosts'  => (int) ($post['repostCount'] ?? 0),
        'replies' => (int) ($post['replyCount'] ?? 0),
        'reach'   => max(1, (int) ($post['likeCount'] ?? 0) + (int) ($post['repostCount'] ?? 0) + 1),
        'raw'     => $post,
      ];
    }
    catch (\Exception $e) {
      $this->logger->warning('Bluesky metrics fetch failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

}
