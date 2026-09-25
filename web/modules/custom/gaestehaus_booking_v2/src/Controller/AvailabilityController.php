<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gaestehaus_booking\Service\AvailabilityService;
use Drupal\gaestehaus_booking\Service\PricingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AvailabilityController extends ControllerBase {

  protected $av;
  protected $pr;

  public function __construct(AvailabilityService $av, PricingService $pr) {
    $this->av = $av; $this->pr = $pr;
  }

  public static function create(ContainerInterface $c) {
    return new static($c->get('gaestehaus_booking.availability_service'), $c->get('gaestehaus_booking.pricing_service'));
  }

  public function get(Request $request): JsonResponse {
    $year  = (int)($request->query->get('year')  ?: date('Y'));
    $month = (int)($request->query->get('month') ?: date('n'));
    if ($month < 1 || $month > 12 || $year < 2020 || $year > 2040) {
      return new JsonResponse(['error' => 'Ungültige Parameter'], 400);
    }
    $days = $this->av->getMonthAvailability($year, $month);
    foreach ($days as $ds => &$day) {
      $date = new \DateTime($ds);
      if ($day['mode'] === 'weekend') {
        $dow = (int)$date->format('w');
        $sat = $dow === 6 ? $date : (clone $date)->modify('-1 day');
        $day['price'] = $this->pr->getWeekendPrice($sat);
      } else {
        $day['tagesplatz_price'] = $this->pr->getTagesplatzPriceForDate($date);
      }
    }
    $r = new JsonResponse(['opening_date' => $this->av->getOpeningDate()->format('Y-m-d'), 'days' => $days]);
    $r->headers->set('Cache-Control', 'no-store');
    return $r;
  }
}
