<?php

/**
 * @file
 * Deploy-Hooks für gaestehaus_booking (ausgeführt von drush deploy).
 */

/**
 * Legt die Zimmer-Nodes an, falls sie fehlen (z. B. nach --existing-config).
 */
function gaestehaus_booking_deploy_create_rooms(): string {
  \Drupal::moduleHandler()->loadInclude('gaestehaus_booking', 'install');
  _gaestehaus_booking_create_rooms();
  return 'Zimmer-Nodes geprüft bzw. angelegt.';
}
