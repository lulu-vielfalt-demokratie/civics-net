<?php
namespace Drupal\civicos_social\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SocialPostForm extends FormBase {

  protected $httpClient;

  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('http_client'));
  }

  public function getFormId() {
    return 'civicos_social_post_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Post-Text'),
      '#description' => $this->t('Maximal 300 Zeichen. Tipp: Kurz, klar, mit Aufruf zur Handlung.'),
      '#rows' => 4,
      '#maxlength' => 300,
      '#required' => TRUE,
    ];

    $form['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Link (optional)'),
      '#description' => $this->t('z.B. https://gerechtgehtanders.com/kalender'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Auf Bluesky veröffentlichen'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::config('civicos_social.settings');
    $api_endpoint = rtrim($config->get('api_endpoint'), '/');
    $api_key = $config->get('api_key');
    $feed_id = $config->get('feed_id');

    try {
      $response = $this->httpClient->post("{$api_endpoint}/feed/{$feed_id}/post", [
        'headers' => ['x-api-key' => $api_key],
        'json' => [
          'text' => $form_state->getValue('text'),
          'url' => $form_state->getValue('url'),
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $this->messenger()->addStatus($this->t('✅ Post veröffentlicht!'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Fehler: @error', ['@error' => $e->getMessage()]));
    }
  }
}
