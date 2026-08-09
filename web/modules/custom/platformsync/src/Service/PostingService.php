<?php

namespace Drupal\platformsync\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Posts content directly to social media platforms.
 *
 * Supported: Mastodon, Bluesky, Telegram
 * Planned:   LinkedIn, Twitter/X, Threads, Instagram
 */
class PostingService {

  protected ClientInterface $httpClient;
  protected ChannelService $channels;
  protected TimeInterface $time;
  protected Connection $database;
  protected LoggerInterface $logger;

  public function __construct(
    ClientInterface $httpClient,
    ChannelService $channels,
    TimeInterface $time,
    Connection $database,
    LoggerInterface $logger
  ) {
    $this->httpClient = $httpClient;
    $this->channels   = $channels;
    $this->time       = $time;
    $this->database   = $database;
    $this->logger     = $logger;
  }

  /**
   * Post to a channel directly.
   *
   * @return array ['success' => bool, 'post_id' => string, 'post_url' => string, 'error' => string|null]
   */
  public function post(int $cid, string $text, string $cardUrl = ''): array {
    $credentials = $this->channels->getCredentials($cid);
    if (!$credentials) {
      return $this->errorResult('Channel credentials not found.');
    }

    $channel = $this->database->select('platformsync_channels', 'c')
      ->fields('c', ['platform'])
      ->condition('c.cid', $cid)
      ->execute()->fetchObject();

    if (!$channel) {
      return $this->errorResult('Channel not found.');
    }

    // Card-URL in Credentials temporär injizieren
    if ($cardUrl) {
      $credentials['card_url'] = $cardUrl;
    }

    switch ($channel->platform) {
      case 'mastodon':
        return $this->postMastodon($credentials, $text);
      case 'bluesky':
      case 'eurosky':
        return $this->postBluesky($credentials, $text);
      case 'telegram':
        return $this->postTelegram($credentials, $text);
      case 'linkedin':
        return $this->postLinkedIn($credentials, $text);
      case 'twitter':
        return $this->errorResult('X/Twitter posting coming soon.');
      case 'threads':
        return $this->errorResult('Threads posting coming soon.');
      case 'instagram':
        return $this->errorResult('Instagram posting coming soon.');
      default:
        return $this->errorResult("Unsupported platform: {$channel->platform}");
    }
  }

  /**
   * Post to Mastodon.
   */
  protected function postMastodon(array $creds, string $text): array {
    $instanceUrl = rtrim($creds['instance_url'], '/');
    $token       = $creds['access_token'];

    try {
      $response = $this->httpClient->post("{$instanceUrl}/api/v1/statuses", [
        'headers' => [
          'Authorization' => "Bearer {$token}",
          'Content-Type'  => 'application/json',
        ],
        'json'    => [
          'status'     => $text,
          'visibility' => 'public',
        ],
        'timeout' => 15,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return [
        'success'  => TRUE,
        'post_id'  => $data['id'] ?? '',
        'post_url' => $data['url'] ?? '',
        'error'    => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Mastodon post failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage());
    }
  }

  /**
   * Post to Bluesky via AT Protocol.
   */
  protected function postBluesky(array $creds, string $text): array {
    \Drupal::logger('platformsync')->notice('postBluesky creds keys: @keys, card_url: @url', ['@keys' => implode(',', array_keys($creds)), '@url' => $creds['card_url'] ?? 'LEER']);
    $identifier  = $creds['identifier'];
    $appPassword = $creds['app_password'];

    try {
      // Authenticate
      $pdsUrl = rtrim($creds['pds_url'] ?? 'https://bsky.social', '/');
      $authResp = $this->httpClient->post("{$pdsUrl}/xrpc/com.atproto.server.createSession", [
        'json'    => ['identifier' => $identifier, 'password' => $appPassword],
        'timeout' => 15,
      ]);
      $authData = json_decode($authResp->getBody()->getContents(), TRUE);
      $token    = $authData['accessJwt'];
      $did      = $authData['did'];

      // Link-Card embed wenn URL vorhanden
      $record = [
        '$type'     => 'app.bsky.feed.post',
        'text'      => mb_substr($text, 0, 300),
        'createdAt' => date('c'),
        'langs'     => ['de'],
      ];

      if (!empty($creds['card_url'])) {
        try {
          $pageResp = $this->httpClient->get($creds['card_url'], [
            'timeout' => 8,
            'headers' => ['User-Agent' => 'PlatformSync/1.0'],
          ]);
          $html        = $pageResp->getBody()->getContents();
          $ogTitle     = '';
          $ogDesc      = '';
          $ogImage     = '';
          if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) $ogTitle = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
          if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/', $html, $m)) $ogTitle = $ogTitle ?: html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
          if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) $ogDesc = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
          if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/', $html, $m)) $ogDesc = $ogDesc ?: html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
          if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)) $ogImage = $m[1];
          if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $html, $m)) $ogImage = $ogImage ?: $m[1];
          if (!$ogTitle && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            $ogTitle = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
          }
          // Fallback: Hostname als Titel
          if (empty(trim($ogTitle, '| '))) {
            $parsed  = parse_url($creds['card_url']);
            $ogTitle = $parsed['host'] ?? $creds['card_url'];
          }
          $ogTitle = trim($ogTitle, '| ');

          $external = [
            'uri'         => $creds['card_url'],
            'title'       => mb_substr($ogTitle ?: $creds['card_url'], 0, 300),
            'description' => mb_substr($ogDesc, 0, 1000),
          ];

          // Fallback-Bild wenn kein OG-Image
          if (!$ogImage) {
            $ogImage = 'https://platformsync.de/themes/custom/platformsync_theme/og-fallback.png';
          }
          // Thumbnail hochladen
          if ($ogImage) {
            try {
              $imgResp  = $this->httpClient->get($ogImage, ['timeout' => 8]);
              $imgData  = $imgResp->getBody()->getContents();
              $mimeType = $imgResp->getHeader('Content-Type')[0] ?? 'image/jpeg';
              $mimeType = explode(';', $mimeType)[0];
              $blobResp = $this->httpClient->post("{$pdsUrl}/xrpc/com.atproto.repo.uploadBlob", [
                'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => $mimeType],
                'body'    => $imgData,
                'timeout' => 15,
              ]);
              $blobData = json_decode($blobResp->getBody()->getContents(), TRUE);
              if (isset($blobData['blob'])) {
                $external['thumb'] = $blobData['blob'];
              }
            } catch (\Exception $e) {}
          }

          $record['embed'] = [
            '$type'    => 'app.bsky.embed.external',
            'external' => $external,
          ];
        } catch (\Exception $e) {
          // Link-Card fehlgeschlagen — ohne Embed posten
          \Drupal::logger('platformsync')->error('Link-Card Fehler: @msg', ['@msg' => $e->getMessage()]);
        }
      }

      // Post
      $response = $this->httpClient->post("{$pdsUrl}/xrpc/com.atproto.repo.createRecord", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'json'    => [
          'repo'       => $did,
          'collection' => 'app.bsky.feed.post',
          'record'     => $record,
        ],
        'timeout' => 15,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $rkey = basename($data['uri'] ?? '');
      $handle = explode('.', $identifier)[0];

      return [
        'success'  => TRUE,
        'post_id'  => $data['uri'] ?? '',
        'post_url' => "https://bsky.app/profile/{$identifier}/post/{$rkey}",
        'error'    => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Bluesky post failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage());
    }
  }

  /**
   * Post to Telegram channel.
   */
  protected function postTelegram(array $creds, string $text): array {
    $botToken  = $creds['bot_token'];
    $channelId = $creds['channel_id'];

    try {
      $response = $this->httpClient->post(
        "https://api.telegram.org/bot{$botToken}/sendMessage",
        [
          'json' => [
            'chat_id'    => $channelId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
          ],
          'timeout' => 15,
        ]
      );

      $data    = json_decode($response->getBody()->getContents(), TRUE);
      $msgId   = $data['result']['message_id'] ?? '';
      $channel = ltrim($channelId, '@');

      return [
        'success'  => TRUE,
        'post_id'  => (string) $msgId,
        'post_url' => "https://t.me/{$channel}/{$msgId}",
        'error'    => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Telegram post failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage());
    }
  }

  /**
   * Post to LinkedIn (Organization page).
   */
  protected function postLinkedIn(array $creds, string $text): array {
    $token  = $creds['access_token'];
    $orgId  = $creds['organization_id'];

    try {
      $response = $this->httpClient->post('https://api.linkedin.com/v2/ugcPosts', [
        'headers' => [
          'Authorization'  => "Bearer {$token}",
          'Content-Type'   => 'application/json',
          'X-Restli-Protocol-Version' => '2.0.0',
        ],
        'json' => [
          'author'          => "urn:li:organization:{$orgId}",
          'lifecycleState'  => 'PUBLISHED',
          'specificContent' => [
            'com.linkedin.ugc.ShareContent' => [
              'shareCommentary' => ['text' => $text],
              'shareMediaCategory' => 'NONE',
            ],
          ],
          'visibility' => [
            'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
          ],
        ],
        'timeout' => 15,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      $postId = $data['id'] ?? '';

      return [
        'success'  => TRUE,
        'post_id'  => $postId,
        'post_url' => "https://www.linkedin.com/feed/update/{$postId}/",
        'error'    => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('LinkedIn post failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResult($e->getMessage());
    }
  }

  /**
   * Verify channel credentials by doing a test API call.
   */
  public function verifyChannel(int $cid): bool {
    $credentials = $this->channels->getCredentials($cid);
    if (!$credentials) return FALSE;

    $channel = $this->database->select('platformsync_channels', 'c')
      ->fields('c', ['platform'])
      ->condition('c.cid', $cid)
      ->execute()->fetchObject();

    try {
      // Card-URL in Credentials temporär injizieren
    if ($cardUrl) {
      $credentials['card_url'] = $cardUrl;
    }

    switch ($channel->platform) {
        case 'mastodon':
          $instanceUrl = rtrim($credentials['instance_url'], '/');
          $this->httpClient->get("{$instanceUrl}/api/v1/accounts/verify_credentials", [
            'headers' => ['Authorization' => "Bearer {$credentials['access_token']}"],
            'timeout' => 10,
          ]);
          return TRUE;

        case 'bluesky':
        case 'eurosky':
          $pdsUrl = rtrim($credentials['pds_url'] ?? 'https://bsky.social', '/');
          $this->httpClient->post("{$pdsUrl}/xrpc/com.atproto.server.createSession", [
            'json'    => ['identifier' => $credentials['identifier'], 'password' => $credentials['app_password']],
            'timeout' => 10,
          ]);
          return TRUE;

        case 'telegram':
          $response = $this->httpClient->get(
            "https://api.telegram.org/bot{$credentials['bot_token']}/getMe",
            ['timeout' => 10]
          );
          $data = json_decode($response->getBody()->getContents(), TRUE);
          return $data['ok'] ?? FALSE;

        default:
          return FALSE;
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Channel verification failed for cid @cid: @msg', [
        '@cid' => $cid,
        '@msg' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  protected function errorResult(string $msg): array {
    return ['success' => FALSE, 'post_id' => '', 'post_url' => '', 'error' => $msg];
  }

}
