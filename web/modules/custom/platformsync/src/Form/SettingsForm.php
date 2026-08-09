<?php

namespace Drupal\platformsync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Admin settings form.
 */
class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['platformsync.settings'];
  }

  public function getFormId(): string {
    return 'platformsync_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('platformsync.settings');
    $plans  = $config->get('plans') ?? [];

    $form['api'] = [
      '#type'  => 'details',
      '#title' => $this->t('Anthropic API'),
      '#open'  => TRUE,
    ];
    $form['api']['anthropic_api_key'] = [
      '#type'        => 'password',
      '#title'       => $this->t('Anthropic API Key'),
      '#description' => $this->t('Your sk-ant-... key. Leave empty to keep the existing value.'),
      '#attributes'  => ['autocomplete' => 'off'],
    ];
    $form['api']['anthropic_model'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Model'),
      '#default_value' => $config->get('anthropic_model') ?: 'claude-sonnet-4-20250514',
    ];
    $form['api']['default_campaign_context'] = [
      '#type'          => 'textarea',
      '#title'         => $this->t('Default campaign context'),
      '#description'   => $this->t('Injected into every prompt as background context (e.g. campaign name, region, tone guidelines).'),
      '#default_value' => $config->get('default_campaign_context'),
      '#rows'          => 3,
    ];
    $form['api']['model_provider'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Model provider'),
      '#description'   => $this->t('<strong>plan</strong>: Free → Ollama (kostenlos), Pro/Enterprise → Anthropic. Empfohlen.'),
      '#options'       => [
        'plan'      => 'Plan-basiert (Free=Ollama, Pro/Enterprise=Anthropic)',
        'anthropic' => 'Immer Anthropic',
        'ollama'    => 'Immer Ollama',
        'auto'      => 'Ollama wenn verfügbar, sonst Anthropic',
      ],
      '#default_value' => $config->get('model_provider') ?: 'plan',
    ];
    $form['api']['ollama_url'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Ollama URL'),
      '#default_value' => $config->get('ollama_url') ?: 'http://127.0.0.1:11434',
    ];
    $form['api']['ollama_model'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Ollama Modell'),
      '#default_value' => $config->get('ollama_model') ?: 'llama3.2:3b',
      '#description'   => $this->t('z.B. llama3.2:3b, mistral:7b'),
    ];

    $form['oauth'] = [
      '#type'  => 'details',
      '#title' => $this->t('OAuth2 / API'),
      '#open'  => FALSE,
    ];
    $form['oauth']['token_expiry_seconds'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Token expiry (seconds)'),
      '#default_value' => $config->get('token_expiry_seconds') ?: 3600,
      '#min'           => 300,
    ];
    $form['oauth']['rate_limit_per_hour'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Rate limit per API key per hour'),
      '#default_value' => $config->get('rate_limit_per_hour') ?: 30,
    ];

    $form['monetization'] = [
      '#type'  => 'details',
      '#title' => $this->t('Plans & Monetization'),
      '#open'  => FALSE,
    ];
    foreach (['free','pro','enterprise'] as $planKey) {
      $planData = $plans[$planKey] ?? [];
      $form['monetization']["plan_{$planKey}"] = [
        '#type'        => 'fieldset',
        '#title'       => ucfirst($planKey),
      ];
      $form['monetization']["plan_{$planKey}"]["plan_{$planKey}_label"] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Label'),
        '#default_value' => $planData['label'] ?? ucfirst($planKey),
        '#size'          => 30,
      ];
      $form['monetization']["plan_{$planKey}"]["plan_{$planKey}_credits"] = [
        '#type'          => 'number',
        '#title'         => $this->t('Monthly credits'),
        '#default_value' => $planData['credits_monthly'] ?? 50,
        '#min'           => 0,
      ];
      $form['monetization']["plan_{$planKey}"]["plan_{$planKey}_price"] = [
        '#type'          => 'number',
        '#title'         => $this->t('Price (EUR/month)'),
        '#default_value' => $planData['price_eur'] ?? 0,
        '#min'           => 0,
        '#step'          => 1,
      ];
    }
    $form['monetization']['credit_cost_per_request'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Credits per generation request'),
      '#default_value' => $config->get('credit_cost_per_request') ?: 1,
      '#min'           => 1,
    ];

    $form['logging'] = [
      '#type'  => 'details',
      '#title' => $this->t('Logging & Monitoring'),
      '#open'  => FALSE,
    ];
    $form['logging']['log_retention_days'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Log retention (days)'),
      '#default_value' => $config->get('log_retention_days') ?: 365,
      '#min'           => 30,
    ];
    $form['logging']['monitoring_alert_email'] = [
      '#type'          => 'email',
      '#title'         => $this->t('Alert email'),
      '#description'   => $this->t('Receive alerts on error spikes or credit exhaustion.'),
      '#default_value' => $config->get('monitoring_alert_email'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('platformsync.settings');

    if ($apiKey = $form_state->getValue('anthropic_api_key')) {
      $config->set('anthropic_api_key', $apiKey);
    }

    $plans = $config->get('plans') ?? [];
    foreach (['free','pro','enterprise'] as $planKey) {
      $plans[$planKey]['label']           = $form_state->getValue("plan_{$planKey}_label");
      $plans[$planKey]['credits_monthly'] = (int) $form_state->getValue("plan_{$planKey}_credits");
      $plans[$planKey]['price_eur']       = (int) $form_state->getValue("plan_{$planKey}_price");
    }

    $config
      ->set('model_provider',              $form_state->getValue('model_provider'))
      ->set('ollama_url',                  $form_state->getValue('ollama_url'))
      ->set('ollama_model',                $form_state->getValue('ollama_model'))
      ->set('anthropic_model',             $form_state->getValue('anthropic_model'))
      ->set('default_campaign_context',   $form_state->getValue('default_campaign_context'))
      ->set('token_expiry_seconds',       (int) $form_state->getValue('token_expiry_seconds'))
      ->set('rate_limit_per_hour',        (int) $form_state->getValue('rate_limit_per_hour'))
      ->set('plans',                      $plans)
      ->set('credit_cost_per_request',    (int) $form_state->getValue('credit_cost_per_request'))
      ->set('log_retention_days',         (int) $form_state->getValue('log_retention_days'))
      ->set('monitoring_alert_email',     $form_state->getValue('monitoring_alert_email'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
