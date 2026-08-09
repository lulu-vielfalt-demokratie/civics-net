<?php

namespace Drupal\platformsync_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liefert die Host- und Player-HTML-Seiten aus.
 */
class QuizPageController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Host-Interface (Beamer-Screen).
   */
  public function host(string $session_id): array {
    $session = $this->loadSession($session_id);
    if (!$session) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $node = \Drupal\node\Entity\Node::load($session->quiz_id);
    $questions = $node->get('field_quiz_questions')->value;
    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    return [
      '#theme'      => 'platformsync_quiz_host',
      '#session_id' => $session_id,
      '#quiz_title' => $node->getTitle(),
      '#questions'  => $questions,
      '#stream_url' => $base_url . '/api/quiz/session/' . $session_id . '/stream',
      '#api_base'   => $base_url . '/api/quiz/session/' . $session_id,
      '#player_url' => $base_url . '/quiz/' . $session_id,
      '#attached'   => [
        'library' => ['platformsync_quiz/host'],
      ],
    ];
  }

  /**
   * Player-Interface (Smartphone).
   */
  public function player(string $session_id): array {
    $session = $this->loadSession($session_id);
    if (!$session) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    return [
      '#theme'      => 'platformsync_quiz_player',
      '#session_id' => $session_id,
      '#stream_url' => $base_url . '/api/quiz/session/' . $session_id . '/stream',
      '#api_base'   => $base_url . '/api/quiz/session/' . $session_id,
      '#attached'   => [
        'library' => ['platformsync_quiz/player'],
      ],
    ];
  }

  protected function loadSession(string $session_id): ?object {
    return $this->database->select('platformsync_quiz_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

}
