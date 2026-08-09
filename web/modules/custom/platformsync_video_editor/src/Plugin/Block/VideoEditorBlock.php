<?php

declare(strict_types=1);

namespace Drupal\platformsync_video_editor\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Video-Editor Block.
 *
 * Platzierbar über Admin → Struktur → Blöcke auf jeder Seite.
 */
#[Block(
  id: 'platformsync_video_editor',
  admin_label: new TranslatableMarkup('PlatformSync Video-Editor'),
  category: new TranslatableMarkup('PlatformSync'),
)]
class VideoEditorBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // API-URL für den JavaScript-Client
    $tracks_url = Url::fromRoute('platformsync_video_editor.tracks_api')
      ->setAbsolute(TRUE)
      ->toString();

    return [
      '#theme'    => 'platformsync_video_editor',
      '#tracks_api_url' => $tracks_url,
      '#attached' => [
        'library' => ['platformsync_video_editor/video_editor'],
        // API-URL an drupalSettings übergeben – kein hardcoded URL im JS
        'drupalSettings' => [
          'platformsyncVideoEditor' => [
            'tracksApiUrl' => $tracks_url,
          ],
        ],
      ],
      '#cache' => [
        // Block pro Nutzer cachen (Permissions können unterschiedlich sein)
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIfHasPermission($account, 'access video editor');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return 0; // Block nicht cachen – JS-State ist immer frisch
  }

}
