<?php

declare(strict_types=1);

namespace Drupal\platformsync_video_editor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

class VideoEditorPageController extends ControllerBase {

  public function page(): array {
    $tracks_url = Url::fromRoute('platformsync_video_editor.tracks_api')
      ->setAbsolute(TRUE)
      ->toString();

    return [
      '#theme'    => 'platformsync_video_editor',
      '#tracks_api_url' => $tracks_url,
      '#attached' => [
        'library' => ['platformsync_video_editor/video_editor'],
        'drupalSettings' => [
          'platformsyncVideoEditor' => [
            'tracksApiUrl' => $tracks_url,
          ],
        ],
      ],
    ];
  }

}
