<?php

declare(strict_types=1);

namespace Drupal\platformsync_video_editor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liefert Syndikal-Tracks als JSON für den Video-Editor.
 */
class TracksApiController extends ControllerBase {

  /**
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    return $instance;
  }

  /**
   * Gibt alle veröffentlichten syndikal_track-Nodes als JSON zurück.
   */
  public function tracks(Request $request): JsonResponse {
    $node_storage = $this->entityTypeManager()->getStorage('node');

    $nids = $node_storage->getQuery()
      ->condition('type', 'syndikal_track')
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->accessCheck(TRUE)
      ->execute();

    $tracks = [];

    foreach ($node_storage->loadMultiple($nids) as $node) {
      $file_url = NULL;
      if ($node->hasField('field_track_audio') && !$node->field_track_audio->isEmpty()) {
        $file = $node->field_track_audio->entity;
        if ($file) {
          $file_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
      }

      $val = fn(string $field): ?string =>
        $node->hasField($field) && !$node->get($field)->isEmpty()
          ? trim((string) $node->get($field)->value)
          : NULL;

      $tracks[] = [
        'id'           => (int) $node->id(),
        'title'        => $node->getTitle() ?: NULL,
        'artist'       => $val('field_track_artist'),
        'genre'        => $val('field_track_genre'),
        'duration_fmt' => $val('field_track_duration'),
        'year'         => $val('field_track_year'),
        'license'      => $val('field_track_license'),
        'mood'         => $val('field_track_mood'),
        'file'         => $file_url,
      ];
    }

    return new JsonResponse([
      'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
      'count'        => count($tracks),
      'tracks'       => $tracks,
    ]);
  }

}
