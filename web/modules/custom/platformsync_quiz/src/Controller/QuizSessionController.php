<?php

namespace Drupal\platformsync_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Steuert Quiz-Sessions (erstellen, Frage weiterschalten, Aufloesung zeigen).
 */
class QuizSessionController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * POST /api/quiz/session
   * Body: { "quiz_id": 42 }
   */
  public function createSession(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $quiz_id = (int) ($data['quiz_id'] ?? 0);

    if (!$quiz_id) {
      return new JsonResponse(['error' => 'quiz_id fehlt'], 400);
    }

    $node = \Drupal\node\Entity\Node::load($quiz_id);
    if (!$node || $node->bundle() !== 'quiz') {
      return new JsonResponse(['error' => 'Quiz nicht gefunden'], 404);
    }

    // Kurzer, einpraegasamer Code (6 Zeichen, nur Grossbuchstaben + Ziffern)
    $session_id = $this->generateSessionId();

    $this->database->insert('platformsync_quiz_session')
      ->fields([
        'session_id'       => $session_id,
        'quiz_id'          => $quiz_id,
        'host_uid'         => $this->currentUser()->id(),
        'status'           => 'waiting',
        'current_question' => 0,
        'created'          => time(),
        'changed'          => time(),
      ])
      ->execute();

    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    return new JsonResponse([
      'session_id'  => $session_id,
      'player_url'  => $base_url . '/quiz/' . $session_id,
      'host_url'    => $base_url . '/quiz/' . $session_id . '/host',
      'stream_url'  => $base_url . '/api/quiz/session/' . $session_id . '/stream',
    ]);
  }

  /**
   * POST /api/quiz/session/{session_id}/advance
   * Naechste Frage anzeigen (oder Quiz beenden).
   */
  public function advance(string $session_id): JsonResponse {
    $session = $this->loadSession($session_id);
    if (!$session) {
      return new JsonResponse(['error' => 'Session nicht gefunden'], 404);
    }

    if ($session->status === 'finished') {
      return new JsonResponse(['error' => 'Quiz bereits beendet'], 400);
    }

    $node = \Drupal\node\Entity\Node::load($session->quiz_id);
    $question_count = count($node->get('field_quiz_questions')->getValue());
    $next_index = (int) $session->current_question + 1;

    if ($session->status === 'waiting') {
      // Erste Frage starten
      $new_status = 'question';
      $new_index = 0;
    }
    elseif ($next_index >= $question_count) {
      // Letzte Frage war dran -> beenden
      $new_status = 'finished';
      $new_index = (int) $session->current_question;
    }
    else {
      $new_status = 'question';
      $new_index = $next_index;
    }

    $this->updateSession($session_id, $new_status, $new_index);

    return new JsonResponse([
      'status'           => $new_status,
      'current_question' => $new_index,
    ]);
  }

  /**
   * POST /api/quiz/session/{session_id}/reveal
   * Aufloesung der aktuellen Frage zeigen.
   */
  public function reveal(string $session_id): JsonResponse {
    $session = $this->loadSession($session_id);
    if (!$session || $session->status !== 'question') {
      return new JsonResponse(['error' => 'Keine aktive Frage'], 400);
    }

    $this->updateSession($session_id, 'revealed', (int) $session->current_question);

    // Punkte vergeben
    $this->awardPoints($session_id, (int) $session->current_question, (int) $session->quiz_id);

    return new JsonResponse(['status' => 'revealed']);
  }

  // -------------------------------------------------------------------------

  protected function loadSession(string $session_id): ?object {
    return $this->database->select('platformsync_quiz_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  protected function updateSession(string $session_id, string $status, int $question_index): void {
    $this->database->update('platformsync_quiz_session')
      ->fields([
        'status'           => $status,
        'current_question' => $question_index,
        'changed'          => time(),
      ])
      ->condition('session_id', $session_id)
      ->execute();
  }

  protected function awardPoints(string $session_id, int $question_index, int $quiz_id): void {
    $node = \Drupal\node\Entity\Node::load($quiz_id);
    $questions = $node->get('field_quiz_questions')->getValue();
    $correct = (int) ($questions[$question_index]['correct'] ?? -1);

    // Alle korrekten Antworten fuer diese Frage laden
    $correct_players = $this->database->select('platformsync_quiz_answer', 'a')
      ->fields('a', ['player_token'])
      ->condition('session_id', $session_id)
      ->condition('question_index', $question_index)
      ->condition('answer_index', $correct)
      ->execute()
      ->fetchCol();

    foreach ($correct_players as $token) {
      $this->database->update('platformsync_quiz_player')
        ->expression('score', 'score + 1')
        ->condition('session_id', $session_id)
        ->condition('player_token', $token)
        ->execute();
    }
  }

  protected function generateSessionId(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O/1/I
    $id = '';
    for ($i = 0; $i < 6; $i++) {
      $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    // Kollision pruefen (sehr unwahrscheinlich, aber sauber)
    $exists = $this->database->select('platformsync_quiz_session', 's')
      ->condition('session_id', $id)
      ->countQuery()->execute()->fetchField();
    return $exists ? $this->generateSessionId() : $id;
  }

}
