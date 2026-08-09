<?php

namespace Drupal\platformsync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages customer social media channel credentials.
 *
 * Credentials are stored AES-256 encrypted in the database.
 * The encryption key is derived from Drupal's hash_salt + a server-side secret.
 *
 * Migration path to HashiCorp Vault: replace encrypt()/decrypt() methods.
 */
class ChannelService {

  protected Connection $database;
  protected AccountProxyInterface $currentUser;
  protected TimeInterface $time;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;

  // Supported platforms and their required credential fields
  const PLATFORM_FIELDS = [
    'mastodon'  => ['instance_url', 'access_token'],
    'bluesky'   => ['identifier', 'app_password'],
    'eurosky'   => ['pds_url', 'identifier', 'app_password'],
    'telegram'  => ['bot_token', 'channel_id'],
    'linkedin'  => ['access_token', 'organization_id'],
    'twitter'   => ['api_key', 'api_secret', 'access_token', 'access_token_secret'],
    'threads'   => ['access_token', 'user_id'],
    'instagram' => ['access_token', 'user_id'],
  ];

  public function __construct(
    Connection $database,
    AccountProxyInterface $currentUser,
    TimeInterface $time,
    ConfigFactoryInterface $configFactory,
    LoggerInterface $logger
  ) {
    $this->database      = $database;
    $this->currentUser   = $currentUser;
    $this->time          = $time;
    $this->configFactory = $configFactory;
    $this->logger        = $logger;
  }

  /**
   * Save channel credentials (encrypted).
   */
  public function saveChannel(int $uid, string $platform, string $label, array $credentials): int {
    $encrypted = $this->encrypt(json_encode($credentials));
    $now       = $this->time->getCurrentTime();

    // Kein Dedup mehr — mehrere Kanäle pro Plattform erlaubt

    return (int) $this->database->insert('platformsync_channels')
      ->fields([
        'uid'      => $uid,
        'kid'      => 0,
        'platform' => $platform,
        'label'    => $label,
        'config'   => $encrypted,
        'active'   => 1,
        'verified' => 0,
        'created'  => $now,
        'changed'  => $now,
      ])
      ->execute();
  }

  /**
   * Get decrypted credentials for a channel.
   */
  public function getCredentials(int $cid): ?array {
    $row = $this->database->select('platformsync_channels', 'c')
      ->fields('c')
      ->condition('c.cid', $cid)
      ->condition('c.active', 1)
      ->execute()->fetchObject();

    if (!$row) return NULL;

    $decrypted = $this->decrypt($row->config);
    return $decrypted ? json_decode($decrypted, TRUE) : NULL;
  }

  /**
   * Get all active channels for a user.
   */
  public function getUserChannels(int $uid): array {
    return $this->database->select('platformsync_channels', 'c')
      ->fields('c', ['cid', 'platform', 'label', 'active', 'verified', 'verified_at', 'last_used', 'created'])
      ->condition('c.uid', $uid)
      ->condition('c.active', 1)
      ->orderBy('c.platform')
      ->execute()->fetchAll();
  }

  /**
   * Get a specific channel by uid + platform.
   */
  public function getChannel(int $uid, string $platform): ?object {
    return $this->database->select('platformsync_channels', 'c')
      ->fields('c')
      ->condition('c.uid', $uid)
      ->condition('c.platform', $platform)
      ->condition('c.active', 1)
      ->execute()->fetchObject() ?: NULL;
  }

  /**
   * Mark channel as verified.
   */
  public function markVerified(int $cid, bool $verified = TRUE): void {
    $this->database->update('platformsync_channels')
      ->fields([
        'verified'    => (int) $verified,
        'verified_at' => $verified ? $this->time->getCurrentTime() : NULL,
        'changed'     => $this->time->getCurrentTime(),
      ])
      ->condition('cid', $cid)
      ->execute();
  }

  /**
   * Delete a channel.
   */
  public function deleteChannel(int $cid, int $uid): void {
    $this->database->update('platformsync_channels')
      ->fields(['active' => 0, 'changed' => $this->time->getCurrentTime()])
      ->condition('cid', $cid)
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Required credential fields for a platform.
   */
  public function getPlatformFields(string $platform): array {
    return self::PLATFORM_FIELDS[$platform] ?? [];
  }

  /**
   * Supported platforms.
   */
  public function getSupportedPlatforms(): array {
    return array_keys(self::PLATFORM_FIELDS);
  }

  // ── Encryption ─────────────────────────────────────────────────────────────

  /**
   * Encrypt data using AES-256-CBC.
   * Key derived from Drupal hash_salt + server environment variable.
   */
  protected function encrypt(string $data): string {
    $key    = $this->getEncryptionKey();
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
  }

  /**
   * Decrypt AES-256-CBC encrypted data.
   */
  protected function decrypt(string $encrypted): ?string {
    try {
      $key  = $this->getEncryptionKey();
      $data = base64_decode($encrypted);
      $iv   = substr($data, 0, 16);
      $cipher = substr($data, 16);
      $result = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
      return $result !== FALSE ? $result : NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Channel decryption failed: @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Derive encryption key from Drupal hash_salt + optional env variable.
   *
   * TODO (Roadmap: Prio Mittel): Replace with HashiCorp Vault key retrieval.
   */
  protected function getEncryptionKey(): string {
    $hashSalt  = $this->configFactory->get('platformsync.settings')->get('hash_salt')
      ?? \Drupal::service('settings')->get('hash_salt', '');
    $envSecret = getenv('PLATFORMSYNC_ENCRYPTION_KEY') ?: '';
    return hash('sha256', $hashSalt . $envSecret, TRUE);
  }

}
