<?php

namespace Drupal\gaestehaus_booking\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;
use Drupal\node\NodeInterface;

/**
 * Zeigt Buchungs-Nodes im Admin-Theme an.
 */
class BookingAdminThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  public function applies(RouteMatchInterface $route_match): bool {
    if ($route_match->getRouteName() !== 'entity.node.canonical') {
      return FALSE;
    }
    $node = $route_match->getParameter('node');
    return $node instanceof NodeInterface && $node->bundle() === 'gh_buchung';
  }

  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->configFactory->get('system.theme')->get('admin') ?: NULL;
  }

}
