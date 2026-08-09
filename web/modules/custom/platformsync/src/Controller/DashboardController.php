<?php

namespace Drupal\platformsync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\platformsync\Service\UsageService;
use Drupal\platformsync\Service\SubscriptionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin monitoring dashboard.
 */
class DashboardController extends ControllerBase {

  protected UsageService $usage;
  protected SubscriptionService $subscription;

  public function __construct(UsageService $usage, SubscriptionService $subscription) {
    $this->usage        = $usage;
    $this->subscription = $subscription;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('platformsync.usage'),
      $container->get('platformsync.subscription')
    );
  }

  public function overview(): array {
    $stats = $this->usage->getStats(30);
    $plans = $this->subscription->getPlans();
    $log   = $this->usage->getUserLog(0, 20);

    $planRevenue = 0;
    foreach ($plans as $key => $plan) {
      $cnt          = $stats['requests_by_plan'][$key] ?? 0;
      $planRevenue += $cnt * ($plan['price_eur'] ?? 0);
    }

    $rows = [];
    foreach ($log as $entry) {
      $rows[] = [
        date('Y-m-d H:i', $entry->created),
        $entry->uid,
        $entry->source,
        $entry->platforms,
        $entry->tone,
        $entry->tokens_used,
        $entry->credits_cost,
        [
          'data' => ['#markup' => $entry->status === 'success'
            ? '<span style="color:green">✓</span>'
            : '<span style="color:red">✗ ' . htmlspecialchars($entry->error_msg) . '</span>'],
        ],
      ];
    }

    return [
      '#theme'  => 'platformsync_dashboard',
      '#stats'  => $stats,
      '#plans'  => $plans,
      '#revenue'=> $planRevenue,
      '#log'    => [
        '#type'   => 'table',
        '#header' => ['Time','UID','Source','Platforms','Tone','Tokens','Credits','Status'],
        '#rows'   => $rows,
        '#empty'  => $this->t('No requests yet.'),
      ],
      '#attached' => ['library' => ['platformsync/admin']],
    ];
  }

}
