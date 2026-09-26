<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gaestehaus_booking\Service\AvailabilityService;
use Drupal\gaestehaus_booking\Service\BookingService;
use Drupal\gaestehaus_booking\Service\PricingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class BookingController extends ControllerBase {

  protected $bs; protected $av; protected $pr;

  public function __construct(BookingService $bs, AvailabilityService $av, PricingService $pr) {
    $this->bs = $bs; $this->av = $av; $this->pr = $pr;
  }

  public static function create(ContainerInterface $c) {
    return new static($c->get('gaestehaus_booking.booking_service'), $c->get('gaestehaus_booking.availability_service'), $c->get('gaestehaus_booking.pricing_service'));
  }

  public function book(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!$body) return new JsonResponse(['error' => 'Ungültiger Request-Body'], 400);
    foreach (['type','guest_name','guest_email','persons'] as $f) {
      if (empty($body[$f])) return new JsonResponse(['error' => "Feld '$f' fehlt"], 400);
    }
    // Vor Eröffnung nicht buchbar
    $start = new \DateTime($body['date_start'] ?? $body['saturday'] ?? 'today');
    if ($this->av->isBeforeOpening($start)) {
      return new JsonResponse(['error' => 'openhuus öffnet am ' . $this->av->getOpeningDate()->format('d.m.Y')], 403);
    }
    try {
      $type = $body['type'];
      switch ($type) {
        case 'weekend':
          if (empty($body['saturday'])) return new JsonResponse(['error' => 'saturday fehlt'], 400);
          $sat = new \DateTime($body['saturday']);
          if ($sat->format('w') !== '6') return new JsonResponse(['error' => 'Kein Samstag'], 400);
          if (!$this->av->isWeekendAvailable($sat)) return new JsonResponse(['error' => 'Wochenende belegt'], 409);
          $sun = (clone $sat)->modify('+1 day');
          $price = $this->pr->getWeekendPrice($sat); // Pauschale pro Wochenende (Sa–So)
          $node = $this->bs->createBooking(array_merge($body, ['price_total' => $price]));
          break;
        case 'tagesplatz':
          $s = new \DateTime($body['date_start']); $e = new \DateTime($body['date_end']);
          $price = $this->pr->calcTagesplatzTotal($s, $e);
          $code  = $body['discount_code'] ?? '';
          if ($code) $price = $this->pr->applyDiscountCode($price, $code, $type, $body['date_start']);
          $node = $this->bs->createBooking(array_merge($body, ['price_total' => $price]));
          break;
        case 'zimmer_desk': case 'woche':
          if (empty($body['room'])) return new JsonResponse(['error' => 'room fehlt'], 400);
          $s = new \DateTime($body['date_start']); $e = new \DateTime($body['date_end']);
          if (!$this->av->isRoomAvailable((int)$body['room'], $s, $e)) return new JsonResponse(['error' => 'Zimmer nicht verfügbar'], 409);
          $persons = (int)($body['persons'] ?? 1);
          $p = $this->pr->calcRoomTotal((int)$body['room'], $s, $e, $type, $persons);
          $code = $body['discount_code'] ?? '';
          if ($code) $p['total'] = $this->pr->applyDiscountCode($p['total'], $code, $type, $body['date_start']);
          $node = $this->bs->createBooking(array_merge($body, ['price_total' => $p['total']]));
          break;
        default:
          return new JsonResponse(['error' => 'Unbekannter Buchungstyp'], 400);
      }
      return new JsonResponse(['ok' => TRUE, 'id' => $node->id(), 'price_total' => $body['price_total'] ?? 0]);
    } catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }
}
