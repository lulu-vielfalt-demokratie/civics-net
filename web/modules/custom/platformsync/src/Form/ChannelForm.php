<?php

namespace Drupal\platformsync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\platformsync\Service\ChannelService;
use Drupal\platformsync\Service\PostingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to add or edit a social media channel.
 */
class ChannelForm extends FormBase {

  protected ChannelService $channels;
  protected PostingService $posting;

  public function __construct(ChannelService $channels, PostingService $posting) {
    $this->channels = $channels;
    $this->posting  = $posting;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('platformsync.channel'),
      $container->get('platformsync.posting')
    );
  }

  public function getFormId(): string {
    return 'platformsync_channel_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, int $cid = 0): array {
    $uid      = (int) \Drupal::currentUser()->id();
    $existing = $cid ? $this->channels->getChannel($uid, '') : NULL;

    // If editing, load existing channel
    if ($cid) {
      $row = \Drupal::database()->select('platformsync_channels', 'c')
        ->fields('c')
        ->condition('c.cid', $cid)
        ->condition('c.uid', $uid)
        ->execute()->fetchObject();
      $existing = $row ?: NULL;
    }

    $form_state->set('cid', $cid);
    $form_state->set('existing', $existing);

    $platforms = [
      'mastodon'  => 'Mastodon',
      'bluesky'   => 'Bluesky',
      'eurosky'   => 'Eurosky (AT Protocol, EU)' ,
      'telegram'  => 'Telegram',
      'linkedin'  => 'LinkedIn',
      'twitter'   => 'X / Twitter (coming soon)',
      'threads'   => 'Threads (coming soon)',
      'instagram' => 'Instagram (coming soon)',
    ];

    $form['label'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Name'),
      '#description'   => $this->t('z.B. "Mastodon faktisch.eu" oder "Telegram MV-Kanal"'),
      '#default_value' => $existing ? $existing->label : '',
      '#required'      => TRUE,
    ];

    $form['platform'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Plattform'),
      '#options'       => $platforms,
      '#default_value' => $existing ? $existing->platform : 'mastodon',
      '#required'      => TRUE,
      '#ajax'          => [
        'callback' => '::platformChanged',
        'wrapper'  => 'credentials-wrapper',
        'event'    => 'change',
      ],
      '#disabled'      => (bool) $existing,
    ];

    $selectedPlatform = $form_state->getValue('platform')
      ?? ($existing ? $existing->platform : 'mastodon');

    $form['credentials'] = [
      '#type'       => 'fieldset',
      '#title'      => $this->t('Zugangsdaten'),
      '#prefix'     => '<div id="credentials-wrapper">',
      '#suffix'     => '</div>',
    ];

    // Existing credentials (decrypted, for pre-filling)
    $existingCreds = [];
    if ($existing) {
      $existingCreds = $this->channels->getCredentials($existing->cid) ?? [];
    }

    switch ($selectedPlatform) {
      case 'mastodon':
        $form['credentials']['instance_url'] = [
          '#type'          => 'url',
          '#title'         => $this->t('Instanz-URL'),
          '#description'   => $this->t('z.B. https://mastodon.social oder https://chaos.social'),
          '#default_value' => $existingCreds['instance_url'] ?? '',
          '#required'      => TRUE,
        ];
        $form['credentials']['access_token'] = [
          '#type'        => 'password',
          '#title'       => $this->t('Access Token'),
          '#description' => $this->t('Mastodon → Einstellungen → Entwicklung → Neue Anwendung → Token. Scope: write:statuses'),
          '#required'    => !$existing,
        ];
        break;

      case 'eurosky':
        $form['credentials']['pds_url'] = [
          '#type'          => 'url',
          '#title'         => $this->t('PDS URL'),
          '#default_value' => $existingCreds['pds_url'] ?? 'https://eurosky.social',
          '#required'      => TRUE,
        ];
        $form['credentials']['identifier'] = [
          '#type'          => 'textfield',
          '#title'         => $this->t('Handle'),
          '#description'   => $this->t('z.B. name.eurosky.social'),
          '#default_value' => $existingCreds['identifier'] ?? '',
          '#required'      => TRUE,
        ];
        $form['credentials']['app_password'] = [
          '#type'        => 'password',
          '#title'       => $this->t('App-Passwort'),
          '#description' => $this->t('Eurosky → Einstellungen → App-Passwörter → Neues App-Passwort.'),
          '#required'    => !$existing,
        ];
        break;

      case 'bluesky':
        $form['credentials']['pds_url'] = [
          '#type'          => 'url',
          '#title'         => $this->t('PDS URL'),
          '#description'   => $this->t('Standard: https://bsky.social — nur ändern wenn du einen eigenen PDS-Server nutzt.'),
          '#default_value' => $existingCreds['pds_url'] ?? 'https://bsky.social',
          '#required'      => TRUE,
        ];
        $form['credentials']['identifier'] = [
          '#type'          => 'textfield',
          '#title'         => $this->t('Handle'),
          '#description'   => $this->t('z.B. faktisch.bsky.social'),
          '#default_value' => $existingCreds['identifier'] ?? '',
          '#required'      => TRUE,
        ];
        $form['credentials']['app_password'] = [
          '#type'        => 'password',
          '#title'       => $this->t('App-Passwort'),
          '#description' => $this->t('Bluesky → Einstellungen → App-Passwörter → Neues App-Passwort. Nie das Haupt-Passwort verwenden!'),
          '#required'    => !$existing,
        ];
        break;

      case 'telegram':
        $form['credentials']['bot_token'] = [
          '#type'        => 'password',
          '#title'       => $this->t('Bot Token'),
          '#description' => $this->t('Von @BotFather erhalten. Format: 123456:ABC-DEF...'),
          '#required'    => !$existing,
        ];
        $form['credentials']['channel_id'] = [
          '#type'          => 'textfield',
          '#title'         => $this->t('Channel ID'),
          '#description'   => $this->t('z.B. @meinkanal oder -1001234567890 (numerische ID für private Channels)'),
          '#default_value' => $existingCreds['channel_id'] ?? '',
          '#required'      => TRUE,
        ];
        break;

      case 'linkedin':
        $form['credentials']['access_token'] = [
          '#type'        => 'password',
          '#title'       => $this->t('Access Token'),
          '#description' => $this->t('LinkedIn Developer App → OAuth 2.0 Token'),
          '#required'    => !$existing,
        ];
        $form['credentials']['organization_id'] = [
          '#type'          => 'textfield',
          '#title'         => $this->t('Organisation ID'),
          '#description'   => $this->t('Numerische ID der LinkedIn-Unternehmensseite'),
          '#default_value' => $existingCreds['organization_id'] ?? '',
          '#required'      => TRUE,
        ];
        break;

      default:
        $form['credentials']['info'] = [
          '#markup' => '<p>' . $this->t('Diese Plattform wird in Kürze unterstützt.') . '</p>',
        ];
    }

    $form['verify'] = [
      '#type'        => 'checkbox',
      '#title'       => $this->t('Verbindung nach dem Speichern testen'),
      '#default_value' => TRUE,
    ];

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $existing ? $this->t('Speichern') : $this->t('Kanal hinzufügen'),
    ];

    return $form;
  }

  public function platformChanged(array &$form, FormStateInterface $form_state): array {
    return $form['credentials'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid      = (int) \Drupal::currentUser()->id();
    $cid      = (int) $form_state->get('cid');
    $existing = $form_state->get('existing');
    $platform = $form_state->getValue('platform');
    $label    = $form_state->getValue('label');

    // Build credentials array — Felder direkt in getValues() auf oberster Ebene
    $fields        = $this->channels->getPlatformFields($platform);
    $creds         = [];
    $existingCreds = $existing ? ($this->channels->getCredentials($existing->cid) ?? []) : [];
    $allValues     = $form_state->getValues();

    foreach ($fields as $field) {
      $val = $allValues[$field] ?? '';
      if (!empty($val)) {
        $creds[$field] = $val;
      }
      elseif (isset($existingCreds[$field])) {
        $creds[$field] = $existingCreds[$field];
      }
    }

    // Sicherstellen dass Pflichtfelder nicht leer sind
    $fields = $this->channels->getPlatformFields($platform);
    foreach ($fields as $field) {
      if (empty($creds[$field])) {
        $this->messenger()->addError($this->t('Pflichtfeld "@field" fehlt. Bitte alle Zugangsdaten vollständig eingeben.', ['@field' => $field]));
        return;
      }
    }
    $savedCid = $this->channels->saveChannel($uid, $platform, $label, $creds);

    // Optionally verify
    if ($form_state->getValue('verify') && $savedCid) {
      $ok = $this->posting->verifyChannel($savedCid);
      $this->channels->markVerified($savedCid, $ok);
      if ($ok) {
        $this->messenger()->addMessage($this->t('Kanal gespeichert und Verbindung erfolgreich getestet ✓'));
      }
      else {
        $this->messenger()->addWarning($this->t('Kanal gespeichert, aber Verbindungstest fehlgeschlagen. Bitte Zugangsdaten prüfen.'));
      }
    }
    else {
      $this->messenger()->addMessage($this->t('Kanal gespeichert.'));
    }

    $form_state->setRedirect('platformsync.channels');
  }

}
