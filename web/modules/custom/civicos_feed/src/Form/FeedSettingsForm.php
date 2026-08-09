<?php

namespace Drupal\civicos_feed\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FeedSettingsForm extends ConfigFormBase {

  protected $httpClient;

  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('http_client'));
  }

  protected function getEditableConfigNames() {
    return ['civicos_feed.settings'];
  }

  public function getFormId() {
    return 'civicos_feed_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('civicos_feed.settings');

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('API-Verbindung'),
      '#open' => TRUE,
    ];

    $form['connection']['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API-Endpoint'),
      '#description' => $this->t('z.B. https://feed.civicos.de'),
      '#default_value' => $config->get('api_endpoint') ?? '',
    ];

    $form['connection']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API-Key'),
      '#default_value' => $config->get('api_key') ?? '',
    ];

    $form['connection']['feed_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feed-ID'),
      '#description' => $this->t('z.B. gga-feed'),
      '#default_value' => $config->get('feed_id') ?? '',
    ];

    $form['keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Keywords'),
      '#description' => $this->t('Ein Keyword pro Zeile. Hashtags mit # prefix.'),
      '#default_value' => implode("\n", $config->get('keywords') ?? []),
      '#rows' => 15,
    ];

    $form['blacklist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blacklist (DIDs)'),
      '#description' => $this->t('Ein DID pro Zeile.'),
      '#default_value' => implode("\n", $config->get('blacklist') ?? []),
      '#rows' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $keywords = array_values(array_filter(array_map('trim', explode("\n", $form_state->getValue('keywords')))));
    $blacklist = array_values(array_filter(array_map('trim', explode("\n", $form_state->getValue('blacklist')))));
    $api_endpoint = rtrim($form_state->getValue('api_endpoint'), '/');
    $api_key = $form_state->getValue('api_key');
    $feed_id = $form_state->getValue('feed_id');

    $this->config('civicos_feed.settings')
      ->set('keywords', $keywords)
      ->set('blacklist', $blacklist)
      ->set('api_endpoint', $api_endpoint)
      ->set('api_key', $api_key)
      ->set('feed_id', $feed_id)
      ->save();

    // API aufrufen
    try {
      $this->httpClient->post("{$api_endpoint}/feed/{$feed_id}/keywords", [
        'headers' => ['x-api-key' => $api_key],
        'json' => ['keywords' => $keywords, 'blacklist' => $blacklist],
      ]);
      $this->messenger()->addStatus($this->t('Feed erfolgreich aktualisiert.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('API-Fehler: @error', ['@error' => $e->getMessage()]));
    }

    parent::submitForm($form, $form_state);
  }
}
