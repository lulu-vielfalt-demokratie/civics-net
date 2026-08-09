<?php

namespace Drupal\platformsync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages user subscriptions, plans, and credit balances.
 */
class SubscriptionService {

  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;

  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time
  ) {
    $this->database      = $database;
    $this->configFactory = $configFactory;
    $this->time          = $time;
  }

  /**
   * Get or create subscription record for a user.
   */
  public function getSubscription(int $uid): object {
    $row = $this->database->select('platformsync_subscriptions', 's')
      ->fields('s')
      ->condition('s.uid', $uid)
      ->execute()->fetchObject();

    if (!$row) {
      $config       = $this->configFactory->get('platformsync.settings');
      $plans        = $config->get('plans') ?? [];
      $freePlan     = $plans['free'] ?? ['credits_monthly' => 50];
      $now          = $this->time->getCurrentTime();
      $this->database->insert('platformsync_subscriptions')
        ->fields([
          'uid'           => $uid,
          'plan'          => 'free',
          'credits_total' => (int) $freePlan['credits_monthly'],
          'credits_used'  => 0,
          'reset_date'    => strtotime('first day of next month'),
          'valid_until'   => 0,
          'created'       => $now,
          'changed'       => $now,
        ])
        ->execute();
      $row = $this->getSubscription($uid);
    }

    return $row;
  }

  /**
   * Check if user has enough credits for a request.
   */
  public function hasCredits(int $uid, int $cost = 1): bool {
    $sub = $this->getSubscription($uid);
    $this->maybeResetCredits($sub);
    return ($sub->credits_total - $sub->credits_used) >= $cost;
  }

  /**
   * Deduct credits after a successful generation.
   */
  public function deductCredits(int $uid, int $cost = 1): void {
    $this->database->update('platformsync_subscriptions')
      ->expression('credits_used', 'credits_used + :cost', [':cost' => $cost])
      ->expression('changed', ':now', [':now' => $this->time->getCurrentTime()])
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Upgrade a user's plan.
   */
  public function upgradePlan(int $uid, string $plan): void {
    $config   = $this->configFactory->get('platformsync.settings');
    $plans    = $config->get('plans') ?? [];
    $planData = $plans[$plan] ?? null;
    if (!$planData) {
      throw new \InvalidArgumentException("Unknown plan: $plan");
    }
    $now = $this->time->getCurrentTime();
    $this->database->merge('platformsync_subscriptions')
      ->key('uid', $uid)
      ->fields([
        'plan'          => $plan,
        'credits_total' => (int) $planData['credits_monthly'],
        'credits_used'  => 0,
        'reset_date'    => strtotime('first day of next month'),
        'valid_until'   => strtotime('+1 month', $now),
        'changed'       => $now,
      ])
      ->execute();
  }

  /**
   * Monthly credit reset if the reset_date has passed.
   */
  protected function maybeResetCredits(object $sub): void {
    $now = $this->time->getCurrentTime();
    if ($now >= $sub->reset_date) {
      $config   = $this->configFactory->get('platformsync.settings');
      $plans    = $config->get('plans') ?? [];
      $planData = $plans[$sub->plan] ?? ['credits_monthly' => 50];
      $this->database->update('platformsync_subscriptions')
        ->fields([
          'credits_used' => 0,
          'reset_date'   => strtotime('first day of next month'),
          'changed'      => $now,
        ])
        ->condition('uid', $sub->uid)
        ->execute();
      $sub->credits_used = 0;
      $sub->credits_total = (int) $planData['credits_monthly'];
    }
  }

  /**
   * Return remaining credits.
   */
  public function getRemaining(int $uid): int {
    $sub = $this->getSubscription($uid);
    return max(0, $sub->credits_total - $sub->credits_used);
  }

  /**
   * All plan definitions from config.
   */
  public function getPlans(): array {
    return $this->configFactory->get('platformsync.settings')->get('plans') ?? [];
  }

}
