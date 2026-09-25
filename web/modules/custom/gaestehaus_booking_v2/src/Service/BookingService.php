<?php

namespace Drupal\gaestehaus_booking\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;

class BookingService {

  protected $em; protected $cfg; protected $logger; protected $mail; protected $lm;

  public function __construct(EntityTypeManagerInterface $em, ConfigFactoryInterface $cf, LoggerChannelFactoryInterface $lf, MailManagerInterface $mail, LanguageManagerInterface $lm) {
    $this->em     = $em;
    $this->cfg    = $cf->get('gaestehaus_booking.settings');
    $this->logger = $lf->get('gaestehaus_booking');
    $this->mail   = $mail;
    $this->lm     = $lm;
  }

  public function createBooking(array $data): \Drupal\node\Entity\Node {
    $type = $data['type'];
    $fields = [
      'type'                   => 'gh_buchung',
      'title'                  => $this->buildTitle($data),
      'field_gh_booking_type'  => $type,
      'field_gh_guest_name'    => $data['guest_name'],
      'field_gh_guest_email'   => $data['guest_email'],
      'field_gh_guest_phone'   => $data['guest_phone'] ?? '',
      'field_gh_persons'       => (int)($data['persons'] ?? 1),
      'field_gh_notes'         => $data['notes'] ?? '',
      'field_gh_source'        => $data['source'] ?? 'website',
      'field_gh_status'        => 'confirmed',
      'field_gh_price_total'   => (float)($data['price_total'] ?? 0),
      'status'                 => 0,
      'uid'                    => 1,
    ];

    if ($type === 'weekend') {
      $sat = new \DateTime($data['saturday']);
      $fields['field_gh_weekend_saturday'] = $sat->format('Y-m-d');
      $fields['field_gh_date_start']       = $sat->format('Y-m-d');
      $fields['field_gh_date_end']         = (clone $sat)->modify('+2 days')->format('Y-m-d');
    } else {
      $fields['field_gh_date_start'] = $data['date_start'];
      $fields['field_gh_date_end']   = $data['date_end'];
      if (in_array($type, ['zimmer_desk', 'woche'])) {
        $fields['field_gh_room_number'] = (int)$data['room'];
        $fields['field_gh_tariff']      = $type;
      }
    }

    $node = $this->em->getStorage('node')->create($fields);
    $node->save();

    // Rabattcode als verwendet markieren
    if (!empty($data['discount_code'])) {
      $this->markDiscountCodeUsed($data['discount_code']);
    }

    $this->logger->info('Neue Buchung @id: @type für @guest', ['@id' => $node->id(), '@type' => $type, '@guest' => $data['guest_name']]);
    $this->sendConfirmationMail($node);
    return $node;
  }

  protected function markDiscountCodeUsed(string $code): void {
    $code   = strtoupper(trim($code));
    $config = \Drupal::configFactory()->getEditable('gaestehaus_booking.settings');
    $codes  = $config->get('discount_codes') ?? [];
    if (!isset($codes[$code])) return;
    $codes[$code]['used']   = TRUE;
    $codes[$code]['active'] = FALSE;
    $config->set('discount_codes', $codes)->save();
    $this->logger->info('Rabattcode @code verwendet und deaktiviert.', ['@code' => $code]);
  }

  protected function sendConfirmationMail(\Drupal\node\Entity\Node $node): void {
    $guestEmail = $node->get('field_gh_guest_email')->value;
    $adminEmail = $this->cfg->get('contact_email') ?: \Drupal::config('system.site')->get('mail');
    $langcode   = $this->lm->getDefaultLanguage()->getId();
    $params     = ['node' => $node];
    if ($guestEmail) $this->mail->mail('gaestehaus_booking', 'booking_confirmation_guest', $guestEmail, $langcode, $params);
    $this->mail->mail('gaestehaus_booking', 'booking_confirmation_admin', $adminEmail, $langcode, $params);
  }

  protected function buildTitle(array $data): string {
    $labels = ['weekend' => 'Event', 'tagesplatz' => 'Tagesplatz', 'zimmer_desk' => 'Zimmer+Desk', 'woche' => 'Wochenpauschale'];
    return sprintf('%s — %s — %s', $labels[$data['type']] ?? $data['type'], $data['guest_name'], $data['date_start'] ?? $data['saturday'] ?? date('Y-m-d'));
  }
}
