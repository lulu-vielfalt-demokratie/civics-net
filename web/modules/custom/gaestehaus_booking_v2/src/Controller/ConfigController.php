<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ConfigController extends ControllerBase {

  protected $cfg;

  public function __construct(ConfigFactoryInterface $cf) {
    $this->cfg = $cf->get('gaestehaus_booking.settings');
  }

  public static function create(ContainerInterface $c) {
    return new static($c->get('config.factory'));
  }

  public function get(): JsonResponse {
    $rooms = [];
    $count = (int)($this->cfg->get('room_count') ?? 9);
    for ($i = 1; $i <= $count; $i++) {
      $rooms[$i] = ['name' => "Zimmer $i", 'max_persons' => 2, 'min_stay' => 1];
    }
    return new JsonResponse([
      'rooms'             => $rooms,
      'seasons'           => $this->cfg->get('seasons') ?? [],
      'coworking_tariffs' => ['tagesplatz' => ['label' => 'Tagesplatz', 'unit' => 'tag'], 'zimmer_desk' => ['label' => 'Zimmer + Desk', 'unit' => 'nacht'], 'woche' => ['label' => 'Wochenpauschale', 'unit' => 'woche', 'discount_nights' => 1]],
      'weekend_pricing'   => $this->cfg->get('seasons') ?? [],
      'max_tagesplaetze'  => (int)($this->cfg->get('max_tagesplaetze') ?? 8),
      'opening_date'      => $this->cfg->get('opening_date') ?? '2026-10-12',
    ]);
  }
}
