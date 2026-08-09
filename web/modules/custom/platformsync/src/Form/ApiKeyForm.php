<?php

namespace Drupal\platformsync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\platformsync\Service\OAuthService;
use Drupal\platformsync\Service\SubscriptionService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to create a new API key (OAuth2 client).
 */
class ApiKeyForm extends FormBase {

  protected OAuthService $oauth;
  protected SubscriptionService $subscription;

  public function __construct(OAuthService $oauth, SubscriptionService $subscription) {
    $this->oauth        = $oauth;
    $this->subscription = $subscription;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('platformsync.oauth'),
      $container->get('platformsync.subscription')
    );
  }

  public function getFormId(): string {
    return 'platformsync_api_key_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $plans = $this->subscription->getPlans();
    $planOptions = array_combine(array_keys($plans), array_column($plans, 'label'));

    $form['label'] = [
      '#type'     => 'textfield',
      '#title'    => $this->t('Label'),
      '#required' => TRUE,
    ];
    $form['uid'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Drupal User ID (owner)'),
      '#default_value' => 1,
      '#min'           => 1,
      '#required'      => TRUE,
    ];
    $form['plan'] = [
      '#type'    => 'select',
      '#title'   => $this->t('Plan'),
      '#options' => $planOptions,
    ];
    $form['credits'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Initial credits'),
      '#default_value' => 100,
      '#min'           => 0,
    ];
    $form['submit'] = ['#type' => 'submit', '#value' => $this->t('Create API Key')];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $credentials = $this->oauth->createClient(
      (int) $form_state->getValue('uid'),
      $form_state->getValue('label'),
      $form_state->getValue('plan'),
      (int) $form_state->getValue('credits')
    );

    // Show credentials once — they cannot be retrieved again.
    $this->messenger()->addMessage($this->t(
      'API key created. client_id: @id — client_secret: @secret (save now, shown only once!)',
      ['@id' => $credentials['client_id'], '@secret' => $credentials['client_secret']]
    ));

    $form_state->setRedirect('platformsync.admin_api_keys');
  }

}
