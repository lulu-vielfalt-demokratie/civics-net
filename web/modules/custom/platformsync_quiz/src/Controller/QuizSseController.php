<?php

namespace Drupal\platformsync_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Server-Sent Events Controller.
 *
 * Haelt eine HTTP-Verbindung offen und pusht Zustandsaenderungen
 * der Session an alle verbundenen Clients (Host + Players).
 */
class QuizSseController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * SSE-Stream fuer eine Quiz-Session.
   *
   * GET /api/quiz/session/{session_id}/stream
   */
  public function stream(string $session_id, Request $request): StreamedResponse {
    $response = new StreamedResponse(function () use ($session_id) {
      // SSE-Headers sind bereits gesetzt. Verbindung offen halten.
      $last_changed = 0;
      $max_runtime = 3600; // 1 Stunde maximum
      $start = time();

      // Initiale Session-Daten sofort senden
      $this->sendEvent($this->buildStateEvent($session_id));

      while (true) {
        // Abbruch nach max_runtime
        if (time() - $start > $max_runtime) {
          $this->sendEvent(['type' => 'timeout']);
          break;
        }

        // Client-Verbindung pruefen
        if (connection_aborted()) {
          break;
        }

        // Session-Zustand aus DB lesen
        $session = $this->loadSession($session_id);
        if (!$session) {
          $this->sendEvent(['type' => 'error', 'message' => 'Session nicht gefunden']);
          break;
        }

        // Nur senden wenn sich etwas geaendert hat
        if ($session->changed > $last_changed) {
          $last_changed = $session->changed;
          $this->sendEvent($this->buildStateEvent($session_id, $session));

          // Bei finished: Verbindung sauber schliessen
          if ($session->status === 'finished') {
            break;
          }
        }

        // Heartbeat alle 20 Sekunden (verhindert Proxy-Timeout)
        if (time() % 20 === 0) {
          echo ": heartbeat\n\n";
          ob_flush();
          flush();
        }

        sleep(1);
      }
    });

    $response->headers->set('Content-Type', 'text/event-stream');
    $response->headers->set('Cache-Control', 'no-cache');
    $response->headers->set('X-Accel-Buffering', 'no'); // nginx buffering deaktivieren
    $response->headers->set('Connection', 'keep-alive');

    return $response;
  }

  /**
   * Baut das vollstaendige State-Event fuer eine Session.
   */
  protected function buildStateEvent(string $session_id, ?object $session = NULL): array {
    if (!$session) {
      $session = $this->loadSession($session_id);
    }
    if (!$session) {
      return ['type' => 'error'];
    }

    $event = [
      'type'             => 'state',
      'status'           => $session->status,
      'current_question' => (int) $session->current_question,
      'player_count'     => $this->countPlayers($session_id),
    ];

    // Fragetext und Antworten mitsenden (fuer Player-Screen)
    if (in_array($session->status, ['question', 'revealed'])) {
      $node = \Drupal\node\Entity\Node::load($session->quiz_id);
      $questions_raw = $node->get('field_quiz_questions')->value;
      $questions = json_decode($questions_raw, TRUE);
      $q_index = (int) $session->current_question;
      if (isset($questions[$q_index])) {
        $event['question'] = $questions[$q_index]['question'] ?? '';
        $event['answers']  = $questions[$q_index]['answers'] ?? [];
      }
    }
    // Antwort-Verteilung mitsenden (fuer Host-Screen)
    if (in_array($session->status, ['question', 'revealed'])) {
      $event['answer_counts'] = $this->getAnswerCounts($session_id, (int) $session->current_question);
    }

    // Bei revealed: korrekte Antwort mitsenden
    if ($session->status === 'revealed') {
      $event['correct_index'] = $this->getCorrectIndex($session_id, (int) $session->current_question);
    }

    return $event;
  }

  /**
   * Sendet ein SSE-Event als JSON.
   */
  protected function sendEvent(array $data): void {
    echo 'data: ' . json_encode($data) . "\n\n";
    ob_flush();
    flush();
  }

  protected function loadSession(string $session_id): ?object {
    return $this->database->select('platformsync_quiz_session', 's')
      ->fields('s')
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchObject() ?: NULL;
  }

  protected function countPlayers(string $session_id): int {
    return (int) $this->database->select('platformsync_quiz_player', 'p')
      ->condition('session_id', $session_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  protected function getAnswerCounts(string $session_id, int $question_index): array {
    $counts = [0 => 0, 1 => 0, 2 => 0];
    $result = $this->database->select('platformsync_quiz_answer', 'a')
      ->fields('a', ['answer_index'])
      ->condition('session_id', $session_id)
      ->condition('question_index', $question_index)
      ->execute();
    foreach ($result as $row) {
      $counts[(int) $row->answer_index]++;
    }
    return $counts;
  }

  protected function getCorrectIndex(string $session_id, int $question_index): ?int {
    // Korrekte Antwort aus dem Quiz-Node laden
    $quiz_id = $this->database->select('platformsync_quiz_session', 's')
      ->fields('s', ['quiz_id'])
      ->condition('session_id', $session_id)
      ->execute()
      ->fetchField();

    if (!$quiz_id) {
      return NULL;
    }

    $node = \Drupal\node\Entity\Node::load($quiz_id);
    if (!$node) {
      return NULL;
    }

    $questions_raw = $node->get('field_quiz_questions')->value;
    $questions = json_decode($questions_raw, TRUE);
    if (!isset($questions[$question_index])) {
      return NULL;
    }

    return (int) ($questions[$question_index]['correct'] ?? 0);
  }

}
