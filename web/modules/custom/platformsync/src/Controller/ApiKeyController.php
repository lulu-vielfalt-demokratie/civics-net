<?php

namespace Drupal\platformsync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists and manages API keys.
 */
class ApiKeyController extends ControllerBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function list(): array {
    $rows = $this->database->select('platformsync_api_keys', 'k')
      ->fields('k')
      ->orderBy('k.created', 'DESC')
      ->execute()->fetchAll();

    $tableRows = [];
    foreach ($rows as $row) {
      $tableRows[] = [
        $row->kid,
        $row->label,
        $row->client_id,
        $row->plan,
        $row->credits . ' / ' . $row->credits_used . ' used',
        $row->active ? $this->t('Active') : $this->t('Inactive'),
        date('Y-m-d', $row->created),
        Link::fromTextAndUrl($this->t('Delete'), Url::fromRoute('platformsync.admin_api_key_delete', ['kid' => $row->kid]))->toString(),
      ];
    }

    return [
      [
        '#type'  => 'link',
        '#title' => $this->t('+ Add API Key'),
        '#url'   => Url::fromRoute('platformsync.admin_api_key_add'),
        '#attributes' => ['class' => ['button', 'button--action', 'button--primary']],
      ],
      [
        '#type'   => 'table',
        '#header' => ['ID','Label','Client ID','Plan','Credits','Status','Created',''],
        '#rows'   => $tableRows,
        '#empty'  => $this->t('No API keys yet.'),
      ],
    ];
  }

}
