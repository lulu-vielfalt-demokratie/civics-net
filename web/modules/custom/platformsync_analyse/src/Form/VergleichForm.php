<?php

namespace Drupal\platformsync_analyse\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class VergleichForm extends FormBase {

  public function getFormId() {
    return 'platformsync_vergleich_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'analyse_ergebnis')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    $nodes = Node::loadMultiple($query);

    if (empty($nodes)) {
      $form['hinweis'] = [
        '#markup' => '<p>Keine Analyse-Ergebnisse vorhanden. Bitte zuerst Accounts analysieren.</p>',
      ];
      return $form;
    }

    // Aktuelle und archivierte trennen
    $aktuell  = [];
    $archiv   = [];

    foreach ($nodes as $node) {
      $titel = $node->getTitle();
      if ($node->get('field_analyse_zeitraum')->isEmpty()) {
        $aktuell[$node->id()] = $titel;
      } else {
        $term  = $node->get('field_analyse_zeitraum')->entity;
        $group = $term ? $term->getName() : 'Archiv';
        $archiv[$group][$node->id()] = $titel;
      }
    }

    // Aktuelle Analysen
    if (!empty($aktuell)) {
      $form['aktuell'] = [
        '#type'    => 'checkboxes',
        '#title'   => 'Aktuelle Analysen',
        '#options' => $aktuell,
      ];
    }

    // Archivierte Analysen gruppiert
    if (!empty($archiv)) {
      $form['archiv_header'] = [
        '#markup' => '<h3 style="margin-top:1.5rem;font-size:13px;color:#888;text-transform:uppercase;letter-spacing:.05em;">Archiv</h3>',
      ];
      foreach ($archiv as $gruppe => $optionen) {
        $form['archiv_' . preg_replace('/[^a-z0-9]/', '_', strtolower($gruppe))] = [
          '#type'    => 'checkboxes',
          '#title'   => $gruppe,
          '#options' => $optionen,
        ];
      }
    }

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => 'Vergleich starten',
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values   = $form_state->getValues();
    $selected = [];
    foreach ($values as $key => $val) {
      if (is_array($val)) {
        $selected = array_merge($selected, array_filter($val));
      }
    }
    if (count($selected) < 2) {
      $form_state->setErrorByName('aktuell', 'Bitte mindestens 2 Accounts auswählen.');
    }
    $form_state->set('selected_nids', array_keys($selected));
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = $form_state->get('selected_nids');
    $nodes    = Node::loadMultiple($selected);

    $handles = [];
    foreach ($nodes as $nid => $node) {
      $handle = $node->get('field_ziel_account')->value;
      if ($handle) {
        $handles[$nid] = $handle;
      }
    }

    $handle_list = implode(' ', array_map('escapeshellarg', array_values($handles)));
    $output_file = '/tmp/vergleich_' . time() . '.json';
    $cmd         = "python3 /var/www/platformsync/netzwerk_vergleich.py --accounts $handle_list --output " . escapeshellarg($output_file) . " 2>&1";
    $output      = shell_exec($cmd);

    $data = [];
    if (file_exists($output_file)) {
      $data = json_decode(file_get_contents($output_file), TRUE) ?? [];
      unlink($output_file);
    }

    $titel     = 'Vergleich: ' . implode(' vs. ', array_map(fn($h) => '@' . $h, array_values($handles)));
    $node_refs = array_map(fn($nid) => ['target_id' => $nid], array_keys($handles));

    $vergleich = Node::create([
      'type'                     => 'netzwerk_vergleich',
      'title'                    => $titel . ' – ' . date('d.m.Y H:i'),
      'status'                   => 1,
      'field_analyse_nodes'      => $node_refs,
      'field_vergleich_ergebnis' => [['value' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'format' => 'plain_text']],
      'field_vergleich_summary'  => [['value' => $output ?? '', 'format' => 'plain_text']],
    ]);
    $vergleich->save();

    \Drupal::messenger()->addStatus('Vergleich abgeschlossen. Node ' . $vergleich->id() . ' angelegt.');
    $form_state->setRedirect('entity.node.canonical', ['node' => $vergleich->id()]);
  }
}
