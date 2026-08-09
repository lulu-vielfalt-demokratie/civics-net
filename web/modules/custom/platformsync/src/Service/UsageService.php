<?php

namespace Drupal\platformsync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Records and queries usage log entries.
 */
class UsageService {

  protected Connection $database;
  protected AccountProxyInterface $currentUser;
  protected TimeInterface $time;

  public function __construct(
    Connection $database,
    AccountProxyInterface $currentUser,
    TimeInterface $time
  ) {
    $this->database    = $database;
    $this->currentUser = $currentUser;
    $this->time        = $time;
  }

  public function log(array $data): int {
    $defaults = [
      'uid'          => $this->currentUser->id(),
      'kid'          => 0,
      'source'       => 'drupal',
      'platforms'    => '',
      'tone'         => 'informativ',
      'input_chars'  => 0,
      'output_chars' => 0,
      'tokens_used'  => 0,
      'credits_cost' => 1,
      'status'       => 'success',
      'error_msg'    => '',
      'ip_address'   => \Drupal::request()->getClientIp() ?? '',
      'user_agent'   => substr(\Drupal::request()->headers->get('User-Agent', ''), 0, 512),
      'created'      => $this->time->getCurrentTime(),
    ];
    $row = array_merge($defaults, $data);
    return (int) $this->database->insert('platformsync_usage_log')
      ->fields($row)
      ->execute();
  }

  public function getStats(int $days = 30): array {
    $since = $this->time->getCurrentTime() - ($days * 86400);

    $total = $this->database->select('platformsync_usage_log', 'l')
      ->condition('l.created', $since, '>=')
      ->countQuery()->execute()->fetchField();

    $success = $this->database->select('platformsync_usage_log', 'l')
      ->condition('l.created', $since, '>=')
      ->condition('l.status', 'success')
      ->countQuery()->execute()->fetchField();

    $tokensQ = $this->database->select('platformsync_usage_log', 'l')
      ->condition('l.created', $since, '>=');
    $tokensQ->addExpression('SUM(l.tokens_used)', 'total');
    $tokens = $tokensQ->execute()->fetchField();

    $creditsQ = $this->database->select('platformsync_usage_log', 'l')
      ->condition('l.created', $since, '>=');
    $creditsQ->addExpression('SUM(l.credits_cost)', 'total');
    $credits = $creditsQ->execute()->fetchField();

    $byPlan = $this->database->select('platformsync_usage_log', 'l')
      ->condition('l.created', $since, '>=')
      ->groupBy('s.plan');
    $byPlan->join('platformsync_subscriptions', 's', 'l.uid = s.uid');
    $byPlan->addExpression('COUNT(*)', 'cnt');
    $byPlan->fields('s', ['plan']);
    $planRows = $byPlan->execute()->fetchAllKeyed();

    $daily = $this->database->query(
      "SELECT DATE(FROM_UNIXTIME(created)) AS day, COUNT(*) AS cnt
       FROM {platformsync_usage_log}
       WHERE created >= :since
       GROUP BY day ORDER BY day ASC",
      [':since' => $since]
    )->fetchAllKeyed();

    return [
      'total_requests'   => (int) $total,
      'successful'       => (int) $success,
      'total_tokens'     => (int) $tokens,
      'total_credits'    => (int) $credits,
      'requests_by_plan' => $planRows,
      'daily'            => $daily,
    ];
  }

  public function getUserLog(int $uid = 0, int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('platformsync_usage_log', 'l')
      ->fields('l')
      ->orderBy('l.created', 'DESC')
      ->range($offset, $limit);
    if ($uid > 0) {
      $query->condition('l.uid', $uid);
    }
    return $query->execute()->fetchAll();
  }

  public function purgeOldLogs(int $days): int {
    $threshold = $this->time->getCurrentTime() - ($days * 86400);
    return (int) $this->database->delete('platformsync_usage_log')
      ->condition('created', $threshold, '<')
      ->execute();
  }

}
