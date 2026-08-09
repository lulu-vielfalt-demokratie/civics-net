<?php
namespace Drupal\civicos_social\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SocialSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['civicos_social.settings'];
  }

  public function getFormId() {
    return 'civicos_social_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('civicos_social.settings');

    $form['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API-Endpoint'),
      '#default_value' => $config->get('api_endpoint') ?? 'https://feed.civicos.de',
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API-Key'),
      '#default_value' => $config->get('api_key') ?? '',
    ];

    $form['feed_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Feed-ID'),
      '#default_value' => $config->get('feed_id') ?? 'gga-feed',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('civicos_social.settings')
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('feed_id', $form_state->getValue('feed_id'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
