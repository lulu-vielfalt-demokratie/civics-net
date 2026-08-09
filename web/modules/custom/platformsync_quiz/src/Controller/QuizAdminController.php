<?php

namespace Drupal\platformsync_quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Uebersichtsseite fuer Quiz-Sessions im Drupal-Backend.
 */
class QuizAdminController extends ControllerBase {

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * GET /admin/platformsync/quiz
   */
  public function overview(): array {
    // Alle Quiz-Nodes laden
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'quiz')
      ->accessCheck(TRUE)
      ->sort('created', 'DESC');
    $nids = $query->execute();
    $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

    $rows = [];
    foreach ($nodes as $node) {
      $session_url = Url::fromRoute('platformsync_quiz.session.create')->toString();
      $rows[] = [
        $node->id(),
        $node->getTitle(),
        $node->get('field_quiz_round')->value ?? '—',
        count($node->get('field_quiz_questions')->getValue()) . ' Fragen',
        \Drupal::service('date.formatter')->format($node->getCreatedTime(), 'short'),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => 'Bearbeiten',
                'url' => $node->toUrl('edit-form'),
              ],
            ],
          ],
        ],
      ];
    }

    $build['intro'] = [
      '#markup' => '<p>Quiz-Sessions werden per API gestartet: <code>POST /api/quiz/session</code> mit <code>{"quiz_id": ID}</code>.</p>',
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => ['ID', 'Titel', 'Rundentyp', 'Fragen', 'Erstellt', 'Aktionen'],
      '#rows' => $rows,
      '#empty' => 'Noch kein Quiz angelegt. Neuen Inhalt vom Typ "Quiz" erstellen.',
    ];

    $build['create_link'] = [
      '#type' => 'link',
      '#title' => '+ Neues Quiz erstellen',
      '#url' => Url::fromRoute('node.add', ['node_type' => 'quiz']),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $build;
  }

}
