<?php

namespace Drupal\gaestehaus_booking\Controller;

use Drupal\Core\Controller\ControllerBase;

class BookingPageController extends ControllerBase {

  public function page(): array {
    return [
      '#theme'    => 'gaestehaus_buchung_page',
      '#attached' => ['library' => ['gaestehaus_booking/buchung']],
      '#cache'    => ['max-age' => 0],
    ];
  }
}
