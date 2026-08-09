<?php

namespace Drupal\platformsync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\platformsync\Service\ChannelService;
use Drupal\platformsync\Service\PostingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Manages "Meine Kanäle" — customer social media channel credentials.
 */
class ChannelController extends ControllerBase {

  protected ChannelService $channels;
  protected PostingService $posting;

  public function __construct(ChannelService $channels, PostingService $posting) {
    $this->channels = $channels;
    $this->posting  = $posting;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('platformsync.channel'),
      $container->get('platformsync.posting')
    );
  }

  /**
   * "Meine Kanäle" overview page.
   */
  public function page(): array {
    $uid      = (int) $this->currentUser()->id();
    $channels = $this->channels->getUserChannels($uid);

    $platformLabels = [
      'mastodon'  => 'Mastodon',
      'bluesky'   => 'Bluesky',
      'telegram'  => 'Telegram',
      'linkedin'  => 'LinkedIn',
      'twitter'   => 'X / Twitter',
      'threads'   => 'Threads',
      'instagram' => 'Instagram',
    ];

    $rows = [];
    foreach ($channels as $ch) {
      $rows[] = [
        $platformLabels[$ch->platform] ?? $ch->platform,
        $ch->label,
        $ch->verified
          ? ['data' => ['#markup' => '<span style="color:green">✓ Verifiziert</span>']]
          : ['data' => ['#markup' => '<span style="color:orange">⚠ Nicht verifiziert</span>']],
        $ch->last_used ? date('d.m.Y H:i', $ch->last_used) : '—',
        ['data' => ['#markup' =>
          '<a href="/platformsync/channels/' . $ch->cid . '/edit">Bearbeiten</a> | ' .
          '<a href="/platformsync/channels/' . $ch->cid . '/verify">Testen</a> | ' .
          '<a href="/platformsync/channels/' . $ch->cid . '/delete" onclick="return confirm(\'Kanal löschen?\')">Löschen</a>'
        ]],
      ];
    }

    return [
      [
        '#type'       => 'link',
        '#title'      => $this->t('+ Kanal hinzufügen'),
        '#url'        => \Drupal\Core\Url::fromRoute('platformsync.channel_add'),
        '#attributes' => ['class' => ['button', 'button--action', 'button--primary']],
        '#prefix'     => '<div style="margin-bottom:1rem">',
        '#suffix'     => '</div>',
      ],
      [
        '#type'   => 'table',
        '#header' => ['Plattform', 'Name', 'Status', 'Zuletzt genutzt', 'Aktionen'],
        '#rows'   => $rows,
        '#empty'  => $this->t('Noch keine Kanäle eingerichtet. Füge deinen ersten Kanal hinzu.'),
      ],
    ];
  }

  /**
   * AJAX: Verify channel credentials.
   */
  public function verify(int $cid, Request $request): JsonResponse {
    $uid = (int) $this->currentUser()->id();

    // Ownership check
    $channel = \Drupal::database()->select('platformsync_channels', 'c')
      ->fields('c', ['uid', 'platform'])
      ->condition('c.cid', $cid)
      ->execute()->fetchObject();

    if (!$channel || (int) $channel->uid !== $uid) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Zugriff verweigert.'], 403);
    }

    $ok = $this->posting->verifyChannel($cid);
    $this->channels->markVerified($cid, $ok);

    return new JsonResponse([
      'success' => $ok,
      'message' => $ok
        ? 'Verbindung erfolgreich!'
        : 'Verbindung fehlgeschlagen. Bitte Zugangsdaten prüfen.',
    ]);
  }

  /**
   * AJAX: Post to a specific channel.
   */
  public function postToChannel(Request $request): JsonResponse {
    if (!$this->currentUser()->hasPermission('use platformsync')) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $data = json_decode($request->getContent(), TRUE) ?? [];
    $cid  = (int) ($data['cid'] ?? 0);
    $text = trim($data['text'] ?? '');

    if (!$cid || !$text) {
      return new JsonResponse(['error' => 'cid and text required.'], 400);
    }

    // Ownership check
    $uid     = (int) $this->currentUser()->id();
    $channel = \Drupal::database()->select('platformsync_channels', 'c')
      ->fields('c', ['uid'])
      ->condition('c.cid', $cid)
      ->execute()->fetchObject();

    if (!$channel || (int) $channel->uid !== $uid) {
      return new JsonResponse(['error' => 'Access denied.'], 403);
    }

    $result = $this->posting->post($cid, $text);
    return new JsonResponse($result, $result['success'] ? 200 : 502);
  }

}
