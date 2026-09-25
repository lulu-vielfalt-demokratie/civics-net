<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\gaestehaus_booking\Service\PricingService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class DiscountController extends ControllerBase {

  protected $pr;

  public function __construct(PricingService $pr) {
    $this->pr = $pr;
  }

  public static function create(ContainerInterface $c) {
    return new static($c->get('gaestehaus_booking.pricing_service'));
  }

  public function validate(Request $request): JsonResponse {
    $code = strtoupper(trim($request->query->get('code', '')));
    $type = $request->query->get('type', 'zimmer_desk');
    $date = $request->query->get('date', date('Y-m-d'));

    if (!$code) {
      return new JsonResponse(['valid' => FALSE, 'error' => 'Kein Code angegeben.'], 400);
    }

    $result = $this->pr->validateDiscountCode($code, $type, $date);
    return new JsonResponse($result);
  }
}
