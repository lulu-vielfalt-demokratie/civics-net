<?php

namespace Drupal\platformsync_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Nimmt Antworten von Spieler:innen entgegen.
 */
class QuizAnswerController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * POST /api/quiz/session/{session_id}/answer
   * Body: { "player_token": "abc123", "nickname": "Joan", "answer_index": 1 }
   */
  public function submit(string $session_id, Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);

    $player_token = trim($data['player_token'] ?? '');
    $nickname     = mb_substr(trim($data['nickname'] ?? ''), 0, 40);
    $answer_index = (int) ($data['answer_index'] ?? -1);

    if (!$player_token || $answer_index < 0 || $answer_index > 2) {
      return new JsonResponse(['error' => 'Ungueltige Daten'], 400);
    }

    // Session laden und pruefen
    $session = $this->database->select('platformsync_quiz_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchObject();

    if (!$session || $session->status !== 'question') {
      return new JsonResponse(['error' => 'Keine aktive Frage'], 400);
    }

    $question_index = (int) $session->current_question;

    // Spieler:in registrieren oder aktualisieren
    $this->upsertPlayer($session_id, $player_token, $nickname);

    // Doppelte Antwort verhindern
    $already = $this->database->select('platformsync_quiz_answer', 'a')
      ->condition('session_id', $session_id)
      ->condition('question_index', $question_index)
      ->condition('player_token', $player_token)
      ->countQuery()->execute()->fetchField();

    if ($already) {
      return new JsonResponse(['error' => 'Bereits geantwortet'], 409);
    }

    // Antwort speichern
    $this->database->insert('platformsync_quiz_answer')
      ->fields([
        'session_id'      => $session_id,
        'question_index'  => $question_index,
        'player_token'    => $player_token,
        'answer_index'    => $answer_index,
        'is_correct'      => 0, // wird bei reveal() gesetzt
        'answered'        => time(),
      ])
      ->execute();

    // changed-Timestamp der Session aktualisieren -> SSE-Clients merken es
    $this->database->update('platformsync_quiz_session')
      ->fields(['changed' => time()])
      ->condition('session_id', $session_id)
      ->execute();

    return new JsonResponse(['status' => 'ok']);
  }

  protected function upsertPlayer(string $session_id, string $token, string $nickname): void {
    $exists = $this->database->select('platformsync_quiz_player', 'p')
      ->condition('session_id', $session_id)
      ->condition('player_token', $token)
      ->countQuery()->execute()->fetchField();

    if ($exists) {
      $this->database->update('platformsync_quiz_player')
        ->fields(['nickname' => $nickname])
        ->condition('session_id', $session_id)
        ->condition('player_token', $token)
        ->execute();
    }
    else {
      $this->database->insert('platformsync_quiz_player')
        ->fields([
          'session_id'   => $session_id,
          'player_token' => $token,
          'nickname'     => $nickname,
          'score'        => 0,
          'joined'       => time(),
        ])
        ->execute();
    }
  }

}
