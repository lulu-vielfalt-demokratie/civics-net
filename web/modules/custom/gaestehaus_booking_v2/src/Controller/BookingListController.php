<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin-Übersicht aller Buchungen.
 */
class BookingListController extends ControllerBase {

  public function list(Request $request): array {
    $type   = (string) $request->query->get('typ', '');
    $status = (string) $request->query->get('status', '');
    $zeit   = (string) $request->query->get('zeit', 'kommend');

    $typeOptions   = $this->allowed('field_gh_booking_type');
    $statusOptions = $this->allowed('field_gh_status');
    $zeitOptions   = ['kommend' => 'Kommend', 'vergangen' => 'Vergangen', 'alle' => 'Alle'];
    if (!isset($zeitOptions[$zeit])) $zeit = 'kommend';

    $storage = $this->entityTypeManager()->getStorage('node');
    $q = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'gh_buchung');
    if ($type !== '' && isset($typeOptions[$type])) $q->condition('field_gh_booking_type', $type);
    if ($status !== '' && isset($statusOptions[$status])) $q->condition('field_gh_status', $status);
    $nodes = $storage->loadMultiple($q->execute());

    $today = (new \DateTime('today'))->format('Y-m-d');
    $items = [];
    foreach ($nodes as $n) {
      $t = $n->get('field_gh_booking_type')->value;
      if ($t === 'weekend') {
        $s = $n->get('field_gh_weekend_saturday')->value;
        $e = $s ? (new \DateTime($s))->modify('+1 day')->format('Y-m-d') : NULL;
      }
      else {
        $s = $n->get('field_gh_date_start')->value;
        $e = $n->get('field_gh_date_end')->value;
      }
      if ($zeit === 'kommend' && $e && $e < $today) continue;
      if ($zeit === 'vergangen' && (!$e || $e >= $today)) continue;
      $items[] = ['n' => $n, 't' => $t, 's' => $s, 'e' => $e];
    }
    usort($items, fn($a, $b) => strcmp((string) $a['s'], (string) $b['s']));
    if ($zeit === 'vergangen') $items = array_reverse($items);

    $rows = [];
    foreach ($items as $i) {
      $n  = $i['n'];
      $st = $n->get('field_gh_status')->value;
      $rows[] = [
        $this->fmt($i['s']),
        $this->fmt($i['e']),
        $typeOptions[$i['t']] ?? ($i['t'] ?: '–'),
        in_array($i['t'], ['zimmer_desk', 'woche'], TRUE) ? ($n->get('field_gh_room_number')->value ?: '–') : '–',
        $n->get('field_gh_persons')->value ?? '–',
        $n->get('field_gh_guest_name')->value ?: $n->label(),
        $n->get('field_gh_source')->value ?: 'online',
        $statusOptions[$st] ?? ($st ?: '–'),
        ['data' => [
          '#type'  => 'operations',
          '#links' => [
            'view' => ['title' => $this->t('Ansehen'), 'url' => $n->toUrl()],
            'edit' => ['title' => $this->t('Bearbeiten'), 'url' => $n->toUrl('edit-form')],
          ],
        ]],
      ];
    }

    $query = array_filter(['typ' => $type, 'status' => $status, 'zeit' => $zeit]);

    return [
      'neu' => [
        '#type'       => 'link',
        '#title'      => $this->t('+ Neue Buchung'),
        '#url'        => Url::fromRoute('node.add', ['node_type' => 'gh_buchung']),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#prefix'     => '<p>',
        '#suffix'     => '</p>',
      ],
      'f_zeit'   => $this->filterBar('Zeitraum', 'zeit', $zeitOptions, $zeit, $query, FALSE),
      'f_typ'    => $this->filterBar('Typ', 'typ', $typeOptions, $type, $query),
      'f_status' => $this->filterBar('Status', 'status', $statusOptions, $status, $query),
      'table' => [
        '#type'   => 'table',
        '#header' => ['Anreise', 'Abreise', 'Typ', 'Zimmer', 'Pers.', 'Name', 'Quelle', 'Status', ''],
        '#rows'   => $rows,
        '#empty'  => $this->t('Keine Buchungen gefunden.'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  protected function filterBar(string $label, string $key, array $options, string $current, array $query, bool $withAll = TRUE): array {
    $bar = [
      '#type'       => 'container',
      '#attributes' => ['style' => 'margin: 0 0 .6em;'],
      'label'       => ['#markup' => '<strong style="display:inline-block;min-width:5.5em">' . $label . ':</strong> '],
    ];
    $opts = $withAll ? ['' => 'Alle'] + $options : $options;
    foreach ($opts as $val => $text) {
      $q = $query;
      if ($val === '') unset($q[$key]); else $q[$key] = $val;
      $classes = ['button', 'button--small'];
      if ((string) $val === $current) $classes[] = 'button--primary';
      $bar['l_' . ($val === '' ? 'all' : $val)] = [
        '#type'       => 'link',
        '#title'      => $text,
        '#url'        => Url::fromRoute('gaestehaus_booking.list', [], ['query' => $q]),
        '#attributes' => ['class' => $classes],
      ];
    }
    return $bar;
  }

  protected function allowed(string $field): array {
    $fs = FieldStorageConfig::loadByName('node', $field);
    $v  = $fs ? (array) $fs->getSetting('allowed_values') : [];
    if ($v && array_is_list($v)) {
      $m = [];
      foreach ($v as $i) $m[$i['value']] = $i['label'];
      $v = $m;
    }
    return $v;
  }

  protected function fmt(?string $d): string {
    if (!$d) return '–';
    $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    $dt = new \DateTime($d);
    return $days[(int) $dt->format('w')] . ' ' . $dt->format('d.m.Y');
  }

}
