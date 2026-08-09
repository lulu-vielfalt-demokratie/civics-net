<?php

namespace Drupal\platformsync_analyse\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class AnalyseForm extends FormBase {

  public function getFormId() {
    return 'platformsync_analyse_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['handle'] = [
      '#type'        => 'textfield',
      '#title'       => 'Bluesky Handle',
      '#description' => 'z.B. niusde.bsky.social',
      '#required'    => TRUE,
    ];
    $form['min_followers'] = [
      '#type'          => 'number',
      '#title'         => 'Min. Follower für Extended-Analyse',
      '#default_value' => 1000,
    ];
    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => 'Analyse starten',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $handle      = $form_state->getValue('handle');
    $output_file = '/tmp/analyse_' . preg_replace('/[^a-z0-9]/', '_', $handle) . '_' . time() . '.json';

    $cmd    = "python3 /var/www/platformsync/analyse.py --target " . escapeshellarg($handle) . " --output " . escapeshellarg($output_file) . " 2>&1";
    $output = shell_exec($cmd);

    $data = [];
    if (file_exists($output_file)) {
      $data = json_decode(file_get_contents($output_file), TRUE) ?? [];
      unlink($output_file);
    }

    $gesamt      = $data['gesamt'] ?? 0;
    $verdaechtig = $data['verdaechtig'] ?? 0;

    $node = Node::create([
      'type'                    => 'analyse_ergebnis',
      'title'                   => 'Analyse: @' . $handle . ' – ' . date('d.m.Y H:i'),
      'status'                  => 1,
      'field_ziel_account'      => $handle,
      'field_follower_gesamt'   => $gesamt,
      'field_verdaechtig_count' => $verdaechtig,
      'field_verdaechtig_pct'   => $gesamt ? round($verdaechtig / $gesamt * 100, 1) : 0,
      'field_analyse_datum'     => date('Y-m-d\TH:i:s'),
      'field_rohdaten'          => [['value' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'format' => 'plain_text']],
      'field_bericht'           => [['value' => $output ?? '', 'format' => 'plain_text']],
    ]);
    $node->save();

    \Drupal::messenger()->addStatus(
      'Analyse abgeschlossen: ' . $gesamt . ' Follower, ' . $verdaechtig . ' verdächtig (' . ($gesamt ? round($verdaechtig/$gesamt*100,1) : 0) . '%). Node ' . $node->id() . ' angelegt.'
    );
    $form_state->setRedirect('entity.node.edit_form', ['node' => $node->id()]);
  }
}
