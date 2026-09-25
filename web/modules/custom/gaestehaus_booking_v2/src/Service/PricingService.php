<?php

namespace Drupal\gaestehaus_booking\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

class PricingService {

  protected $config;

  public function __construct(ConfigFactoryInterface $cf) {
    $this->config = $cf->get('gaestehaus_booking.settings');
  }

  public function getSeasonForDate(\DateTime $date): ?array {
    $month   = (int)$date->format('n');
    $seasons = $this->config->get('seasons') ?? [];
    foreach ($seasons as $key => $s) {
      if (in_array($month, $s['months'])) return array_merge($s, ['key' => $key]);
    }
    return NULL;
  }

  protected function getPricingRules(): array {
    return $this->config->get('pricing_rules') ?? [
      'person_surcharge_pct'    => 35,
      'rounding_step'           => 5,
      'lastminute_days'         => 3,
      'lastminute_discount_pct' => 10,
      'earlybird_days'          => 60,
      'earlybird_discount_pct'  => 5,
    ];
  }

  protected function roundPrice(float $price): float {
    $step = (int)($this->getPricingRules()['rounding_step'] ?? 5);
    return $step > 0 ? round($price / $step) * $step : $price;
  }

  public function getDynamicFactor(\DateTime $checkIn): float {
    $rules  = $this->getPricingRules();
    $today  = new \DateTime('today');
    $daysTo = (int)$today->diff($checkIn)->days;
    if ($checkIn <= $today) return 1.0;
    if ($daysTo <= ($rules['lastminute_days'] ?? 3))
      return 1 - (($rules['lastminute_discount_pct'] ?? 10) / 100);
    if ($daysTo >= ($rules['earlybird_days'] ?? 60))
      return 1 - (($rules['earlybird_discount_pct'] ?? 5) / 100);
    return 1.0;
  }

  public function getRoomPriceWithPersons(int $room, \DateTime $date, int $persons = 1): float {
    $rules     = $this->getPricingRules();
    $base      = $this->getRoomPriceForDate($room, $date);
    $factor    = $this->getDynamicFactor($date);
    $surcharge = $persons > 1 ? ($persons - 1) * (($rules['person_surcharge_pct'] ?? 35) / 100) * $base : 0;
    return $this->roundPrice(($base + $surcharge) * $factor);
  }

  public function getRoomPriceForDate(int $room, \DateTime $date): float {
    $s = $this->getSeasonForDate($date);
    return (float)($s['room_prices'][$room] ?? 0);
  }

  public function getTagesplatzPriceForDate(\DateTime $date): float {
    $s = $this->getSeasonForDate($date);
    return (float)($s['tagesplatz_price'] ?? 0);
  }

  public function getWeekendPrice(\DateTime $saturday): float {
    $s = $this->getSeasonForDate($saturday);
    return (float)($s['weekend_price'] ?? 0);
  }

  public function calcRoomTotal(int $room, \DateTime $start, \DateTime $end, string $tariff = 'zimmer_desk', int $persons = 1): array {
    $total = 0.0; $nights = 0; $discount = 0.0;
    $cur = clone $start;
    while ($cur < $end) {
      $total += $this->getRoomPriceWithPersons($room, $cur, $persons);
      $nights++;
      $cur->modify('+1 day');
    }
    $tariffs = $this->config->get('tariffs') ?? [];
    if ($tariff === 'woche' && $nights === ($tariffs['woche']['nights'] ?? 5)) {
      $discount = $this->getRoomPriceWithPersons($room, $start, $persons);
      $total -= $discount;
    }
    return ['nights' => $nights, 'total' => $total, 'discount' => $discount];
  }

  public function calcTagesplatzTotal(\DateTime $start, \DateTime $end): float {
    $total = 0.0; $cur = clone $start;
    while ($cur < $end) { $total += $this->getTagesplatzPriceForDate($cur); $cur->modify('+1 day'); }
    return $total;
  }

  /**
   * Rabattcode validieren und Faktor zurückgeben.
   * @return array ['valid' => bool, 'discount_pct' => int, 'label' => string, 'error' => string]
   */
  public function validateDiscountCode(string $code, string $bookingType, string $date): array {
    $codes = $this->config->get('discount_codes') ?? [];
    $code  = strtoupper(trim($code));

    if (!isset($codes[$code])) {
      return ['valid' => FALSE, 'error' => 'Unbekannter Rabattcode.'];
    }

    $c = $codes[$code];
    if (!($c['active'] ?? TRUE)) {
      return ['valid' => FALSE, 'error' => 'Dieser Code ist nicht mehr aktiv.'];
    }
    if (!empty($c['valid_from']) && $date < $c['valid_from']) {
      return ['valid' => FALSE, 'error' => 'Dieser Code ist noch nicht gültig.'];
    }
    if (!empty($c['valid_until']) && $date > $c['valid_until']) {
      return ['valid' => FALSE, 'error' => 'Dieser Code ist abgelaufen.'];
    }
    if (!empty($c['booking_types']) && !in_array($bookingType, $c['booking_types'])) {
      return ['valid' => FALSE, 'error' => 'Dieser Code gilt nicht für diesen Buchungstyp.'];
    }

    return [
      'valid'        => TRUE,
      'discount_pct' => (int)($c['discount_pct'] ?? 0),
      'label'        => $c['label'] ?? $code,
      'error'        => '',
    ];
  }

  /**
   * Preis nach Rabattcode anpassen.
   */
  public function applyDiscountCode(float $price, string $code, string $bookingType, string $date): float {
    $result = $this->validateDiscountCode($code, $bookingType, $date);
    if (!$result['valid']) return $price;
    $factor = 1 - ($result['discount_pct'] / 100);
    return $this->roundPrice($price * $factor);
  }

  public function getPublicConfig(): array {
    return $this->config->get('seasons') ?? [];
  }
}
