<?php

namespace Drupal\platformsync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\platformsync\Service\ModelService;
use Drupal\platformsync\Service\UsageService;
use Drupal\platformsync\Service\SubscriptionService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles the Drupal UI generation page and AJAX endpoint.
 */
class GenerateController extends ControllerBase {

  protected ModelService $anthropic;
  protected UsageService $usage;
  protected SubscriptionService $subscription;

  public function __construct(
    ModelService $anthropic,
    UsageService $usage,
    SubscriptionService $subscription
  ) {
    $this->anthropic    = $anthropic;
    $this->usage        = $usage;
    $this->subscription = $subscription;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('platformsync.anthropic'),
      $container->get('platformsync.usage'),
      $container->get('platformsync.subscription')
    );
  }

  /**
   * The main generator page — renders the JS-driven UI.
   */
  public function page(): array {
    $uid       = (int) $this->currentUser()->id();
    $remaining = $this->subscription->getRemaining($uid);
    $sub       = $this->subscription->getSubscription($uid);

    return [
      '#theme'    => 'platformsync_generate_page',
      '#remaining'=> $remaining,
      '#plan'     => $sub->plan,
      '#attached' => [
        'library'            => ['platformsync/generate'],
        'drupalSettings'     => [
          'platformSyncApp' => [
            'generateUrl'  => '/platformsync/generate/ajax',
            'csrfToken'    => \Drupal::service('csrf_token')->get('platformsync_generate'),
            'remaining'    => $remaining,
          ],
        ],
      ],
    ];
  }

  /**
   * AJAX endpoint: POST /platformsync/generate/ajax
   */
  public function ajax(Request $request): JsonResponse {
    if (!$this->currentUser()->hasPermission('use platformsync')) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $data      = json_decode($request->getContent(), TRUE) ?? [];
    $text      = trim($data['text'] ?? '');
    $platforms = $data['platforms'] ?? [];
    $tone      = $data['tone'] ?? 'informativ';
    $uid       = (int) $this->currentUser()->id();

    if (empty($text) || empty($platforms)) {
      return new JsonResponse(['error' => 'text and platforms are required.'], 400);
    }

    if (!$this->subscription->hasCredits($uid)) {
      return new JsonResponse(['error' => 'Insufficient credits. Please upgrade your plan.'], 402);
    }

    $sub  = $this->subscription->getSubscription($uid);
    $plan = $sub->plan ?? 'free';
    $result = $this->anthropic->generate($text, $platforms, $tone, '', [], $plan);

    $outputChars = array_sum(array_map('strlen', $result['posts']));
    $status      = $result['error'] ? 'error' : 'success';

    $this->usage->log([
      'uid'          => $uid,
      'source'       => 'drupal',
      'platforms'    => implode(',', $platforms),
      'tone'         => $tone,
      'input_chars'  => strlen($text),
      'output_chars' => $outputChars,
      'tokens_used'  => $result['tokens_used'],
      'credits_cost' => 1,
      'status'       => $status,
      'error_msg'    => $result['error'] ?? '',
    ]);

    if ($status === 'success') {
      $this->subscription->deductCredits($uid, 1);
    }

    if ($result['error']) {
      return new JsonResponse(['error' => $result['error']], 502);
    }

    return new JsonResponse([
      'posts'     => $result['posts'],
      'remaining' => $this->subscription->getRemaining($uid),
    ]);
  }

  /**
   * User's own request history.
   */
  public function history(): array {
    $uid = (int) $this->currentUser()->id();
    $log = $this->usage->getUserLog($uid, 50);
    $rows = [];
    foreach ($log as $entry) {
      $rows[] = [
        date('Y-m-d H:i', $entry->created),
        $entry->platforms,
        $entry->tone,
        $entry->tokens_used,
        $entry->status,
      ];
    }
    return [
      '#type'   => 'table',
      '#header' => ['Time','Platforms','Tone','Tokens','Status'],
      '#rows'   => $rows,
      '#empty'  => $this->t('No requests yet.'),
    ];
  }

}
