<?php

namespace Drupal\platformsync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Password\PasswordInterface;

/**
 * Minimal OAuth2 client_credentials flow for external CMS integrations.
 */
class OAuthService {

  protected Connection $database;
  protected TimeInterface $time;
  protected PasswordInterface $password;

  public function __construct(
    Connection $database,
    TimeInterface $time,
    PasswordInterface $password
  ) {
    $this->database = $database;
    $this->time     = $time;
    $this->password = $password;
  }

  /**
   * Create a new API client (returns plain secret once — never stored).
   */
  public function createClient(int $uid, string $label, string $plan = 'free', int $credits = 100): array {
    $clientId     = $this->generateToken(24);
    $clientSecret = $this->generateToken(48);
    $secretHash   = $this->password->hash($clientSecret);
    $now          = $this->time->getCurrentTime();

    $this->database->insert('platformsync_api_keys')
      ->fields([
        'uid'                => $uid,
        'client_id'          => $clientId,
        'client_secret_hash' => $secretHash,
        'label'              => $label,
        'plan'               => $plan,
        'credits'            => $credits,
        'credits_used'       => 0,
        'active'             => 1,
        'created'            => $now,
        'expires'            => 0,
      ])
      ->execute();

    return [
      'client_id'     => $clientId,
      'client_secret' => $clientSecret,  // shown once only
    ];
  }

  /**
   * Validate client credentials and issue bearer token.
   *
   * Returns ['access_token' => ..., 'expires_in' => ..., 'token_type' => 'Bearer']
   * or throws \RuntimeException on failure.
   */
  public function issueToken(string $clientId, string $clientSecret, int $expiresIn = 3600): array {
    $keyRow = $this->database->select('platformsync_api_keys', 'k')
      ->fields('k')
      ->condition('k.client_id', $clientId)
      ->condition('k.active', 1)
      ->execute()->fetchObject();

    if (!$keyRow) {
      throw new \RuntimeException('Invalid client_id.');
    }
    if ($keyRow->expires > 0 && $keyRow->expires < $this->time->getCurrentTime()) {
      throw new \RuntimeException('Client credentials expired.');
    }
    if (!$this->password->check($clientSecret, $keyRow->client_secret_hash)) {
      throw new \RuntimeException('Invalid client_secret.');
    }

    $token     = $this->generateToken(64);
    $tokenHash = hash('sha256', $token);
    $now       = $this->time->getCurrentTime();

    $this->database->insert('platformsync_oauth_tokens')
      ->fields([
        'kid'        => $keyRow->kid,
        'token_hash' => $tokenHash,
        'scope'      => $keyRow->scope,
        'created'    => $now,
        'expires'    => $now + $expiresIn,
      ])
      ->execute();

    // Clean up expired tokens.
    $this->database->delete('platformsync_oauth_tokens')
      ->condition('expires', $now, '<')
      ->execute();

    return [
      'access_token' => $token,
      'token_type'   => 'Bearer',
      'expires_in'   => $expiresIn,
      'scope'        => $keyRow->scope,
    ];
  }

  /**
   * Validate a bearer token and return the associated API key row.
   */
  public function validateToken(string $bearerToken): ?object {
    $tokenHash = hash('sha256', $bearerToken);
    $now       = $this->time->getCurrentTime();

    $tokenRow = $this->database->select('platformsync_oauth_tokens', 't')
      ->fields('t')
      ->condition('t.token_hash', $tokenHash)
      ->condition('t.expires', $now, '>')
      ->execute()->fetchObject();

    if (!$tokenRow) {
      return null;
    }

    return $this->database->select('platformsync_api_keys', 'k')
      ->fields('k')
      ->condition('k.kid', $tokenRow->kid)
      ->condition('k.active', 1)
      ->execute()->fetchObject();
  }

  /**
   * Check and deduct credits for an API key.
   */
  public function hasCredits(object $keyRow, int $cost = 1): bool {
    return ($keyRow->credits - $keyRow->credits_used) >= $cost;
  }

  public function deductCredits(int $kid, int $cost = 1): void {
    $this->database->update('platformsync_api_keys')
      ->expression('credits_used', 'credits_used + :cost', [':cost' => $cost])
      ->condition('kid', $kid)
      ->execute();
  }

  protected function generateToken(int $length): string {
    return bin2hex(random_bytes((int) ceil($length / 2)));
  }

}
