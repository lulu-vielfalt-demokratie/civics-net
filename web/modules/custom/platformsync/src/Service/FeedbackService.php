<?php

namespace Drupal\platformsync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages user feedback and performance metrics for ML training.
 *
 * Feedback loop:
 *   1. User generates posts → usage_log entry created
 *   2. User rates post (1-5) + marks as published + adds URL
 *   3. Cron fetches engagement metrics from platform APIs (24h/72h)
 *   4. Normalized score stored → available for ML training
 */
class FeedbackService {

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

  /**
   * Save user feedback for a generated post.
   */
  public function saveFeedback(array $data): int {
    $now = $this->time->getCurrentTime();
    $row = [
      'lid'          => $data['lid'],           // usage_log ID
      'uid'          => $this->currentUser->id(),
      'platform'     => $data['platform'],
      'rating'       => (int) ($data['rating'] ?? 0),      // 1-5 Sterne
      'was_used'     => (int) ($data['was_used'] ?? 0),    // wurde veröffentlicht?
      'post_url'     => $data['post_url'] ?? '',            // URL des veröffentlichten Posts
      'post_text'    => $data['post_text'] ?? '',           // tatsächlich verwendeter Text
      'score'        => NULL,                               // wird später durch Metriken befüllt
      'metrics_raw'  => NULL,                               // JSON: likes, boosts, replies, reach
      'metrics_fetched_at' => NULL,
      'created'      => $now,
      'changed'      => $now,
    ];

    return (int) $this->database->insert('platformsync_feedback')
      ->fields($row)
      ->execute();
  }

  /**
   * Update feedback with engagement metrics from platform API.
   *
   * @param int   $fid     Feedback ID
   * @param array $metrics Raw metrics: ['likes' => x, 'boosts' => x, 'replies' => x, 'reach' => x]
   */
  public function updateMetrics(int $fid, array $metrics): void {
    $score = $this->calculateScore($metrics);

    $this->database->update('platformsync_feedback')
      ->fields([
        'metrics_raw'        => json_encode($metrics),
        'score'              => $score,
        'metrics_fetched_at' => $this->time->getCurrentTime(),
        'changed'            => $this->time->getCurrentTime(),
      ])
      ->condition('fid', $fid)
      ->execute();
  }

  /**
   * Normalize engagement metrics into a 0.0–1.0 score.
   *
   * Weights: boosts/reposts > likes > replies (reach as denominator)
   */
  public function calculateScore(array $metrics): float {
    $likes   = (int) ($metrics['likes'] ?? 0);
    $boosts  = (int) ($metrics['boosts'] ?? $metrics['reposts'] ?? 0);
    $replies = (int) ($metrics['replies'] ?? 0);
    $reach   = max(1, (int) ($metrics['reach'] ?? $metrics['impressions'] ?? 1));

    $raw = ($boosts * 0.5) + ($likes * 0.3) + ($replies * 0.2);
    $rate = $raw / $reach;

    // Sigmoid-ähnliche Normalisierung auf 0-1
    return round(min(1.0, $rate * 100), 4);
  }

  /**
   * Get feedback entries pending metric fetching (published, no metrics yet).
   */
  public function getPendingMetricsFetch(int $minAgeHours = 24): array {
    $threshold = $this->time->getCurrentTime() - ($minAgeHours * 3600);

    return $this->database->select('platformsync_feedback', 'f')
      ->fields('f')
      ->condition('f.was_used', 1)
      ->condition('f.post_url', '', '<>')
      ->isNull('f.metrics_fetched_at')
      ->condition('f.created', $threshold, '<=')
      ->execute()->fetchAll();
  }

  /**
   * Get scored training data for ML pipeline.
   * Returns input features + engagement score.
   */
  public function getTrainingData(int $limit = 1000): array {
    $query = $this->database->select('platformsync_feedback', 'f');
    $query->join('platformsync_usage_log', 'l', 'f.lid = l.lid');
    $query->fields('f', ['platform', 'rating', 'score', 'post_text', 'metrics_raw']);
    $query->fields('l', ['input_chars', 'tone', 'tokens_used', 'created']);
    $query->isNotNull('f.score');
    $query->orderBy('f.created', 'DESC');
    $query->range(0, $limit);

    return $query->execute()->fetchAll();
  }

  /**
   * Get few-shot examples for a given platform — highest scored posts.
   */
  public function getFewShotExamples(string $platform, int $limit = 3): array {
    $rows = $this->database->select('platformsync_feedback', 'f')
      ->fields('f', ['post_text', 'score', 'platform'])
      ->condition('f.platform', $platform)
      ->condition('f.score', 0.1, '>=')
      ->isNotNull('f.score')
      ->orderBy('f.score', 'DESC')
      ->range(0, $limit)
      ->execute()->fetchAll();

    return array_map(fn($r) => [
      'platform' => $r->platform,
      'text'     => $r->post_text,
      'score'    => $r->score,
    ], $rows);
  }

  /**
   * Summary stats for ML dashboard.
   */
  public function getStats(): array {
    $total = $this->database->select('platformsync_feedback', 'f')
      ->countQuery()->execute()->fetchField();

    $withScore = $this->database->select('platformsync_feedback', 'f')
      ->isNotNull('f.score')
      ->countQuery()->execute()->fetchField();

    $avgScore = $this->database->select('platformsync_feedback', 'f');
    $avgScore->addExpression('AVG(f.score)', 'avg');
    $avgScore->isNotNull('f.score');
    $avg = $avgScore->execute()->fetchField();

    $byPlatform = $this->database->select('platformsync_feedback', 'f')
      ->fields('f', ['platform'])
      ->groupBy('f.platform');
    $byPlatform->addExpression('COUNT(*)', 'cnt');
    $byPlatform->addExpression('AVG(f.score)', 'avg_score');
    $platforms = $byPlatform->execute()->fetchAll();

    return [
      'total_feedback'   => (int) $total,
      'with_score'       => (int) $withScore,
      'avg_score'        => round((float) $avg, 4),
      'by_platform'      => $platforms,
      'training_ready'   => (int) $withScore >= 50,
    ];
  }

}
