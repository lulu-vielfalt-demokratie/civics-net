<?php

namespace Drupal\platformsync\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\platformsync\Service\ChannelService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ChannelDeleteForm extends ConfirmFormBase {

  protected ChannelService $channels;
  protected int $cid;

  public function __construct(ChannelService $channels) {
    $this->channels = $channels;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('platformsync.channel'));
  }

  public function getFormId(): string {
    return 'platformsync_channel_delete_form';
  }

  public function getQuestion() {
    return $this->t('Kanal wirklich löschen?');
  }

  public function getDescription() {
    return $this->t('Die Zugangsdaten werden unwiderruflich gelöscht.');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('platformsync.channels');
  }

  public function buildForm(array $form, FormStateInterface $form_state, int $cid = 0): array {
    $this->cid = $cid;
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) \Drupal::currentUser()->id();
    $this->channels->deleteChannel($this->cid, $uid);
    $this->messenger()->addMessage($this->t('Kanal gelöscht.'));
    $form_state->setRedirect('platformsync.channels');
  }

}
