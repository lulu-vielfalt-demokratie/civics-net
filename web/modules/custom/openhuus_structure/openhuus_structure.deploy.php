<?php

/**
 * @file
 * Deploy-Hooks für openhuus_structure (ausgeführt von drush deploy).
 *
 * Jeder Hook legt Struktur über die Entity API an bzw. passt sie an und prüft
 * vorher, ob sie schon existiert. Auf Staging entsteht die Struktur hier; auf
 * Prod kommt sie per Config-Import an, die Hooks haben dort nichts mehr zu tun.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\user\Entity\Role;

/**
 * Bildfeld des Medientyps „Bild“: Alternativtext Pflicht, Größe, Endungen.
 */
function openhuus_structure_deploy_0001_bildfeld(): string {
  $field = FieldConfig::loadByName('media', 'image', 'field_media_image');
  if (!$field) {
    return 'Medientyp „Bild“ fehlt – zuerst das Rezept core/recipes/image_media_type anwenden.';
  }
  $field->setSetting('alt_field', TRUE);
  $field->setSetting('alt_field_required', TRUE);
  $field->setSetting('max_resolution', '2560x2560');
  $field->setSetting('file_extensions', 'jpg jpeg png webp');
  $field->save();
  return 'Bildfeld: Alternativtext Pflicht, max. 2560 px, jpg/jpeg/png/webp.';
}

/**
 * Bildstile für Galerie, Hero und Abschnittsbilder (alle als WebP).
 */
function openhuus_structure_deploy_0002_bildstile(): string {
  $styles = [
    'oh_galerie_vorschau' => [
      'label' => 'openhuus – Galerie Vorschau (4:3)',
      'effects' => [
        ['id' => 'image_scale_and_crop', 'data' => ['width' => 800, 'height' => 600, 'anchor' => 'center-center']],
        ['id' => 'image_convert', 'data' => ['extension' => 'webp']],
      ],
    ],
    'oh_galerie_gross' => [
      'label' => 'openhuus – Galerie Großansicht',
      'effects' => [
        ['id' => 'image_scale', 'data' => ['width' => 1920, 'height' => 1920, 'upscale' => FALSE]],
        ['id' => 'image_convert', 'data' => ['extension' => 'webp']],
      ],
    ],
    'oh_hero' => [
      'label' => 'openhuus – Hero (16:9)',
      'effects' => [
        ['id' => 'image_scale_and_crop', 'data' => ['width' => 1920, 'height' => 1080, 'anchor' => 'center-center']],
        ['id' => 'image_convert', 'data' => ['extension' => 'webp']],
      ],
    ],
    'oh_abschnitt' => [
      'label' => 'openhuus – Abschnittsbild',
      'effects' => [
        ['id' => 'image_scale', 'data' => ['width' => 1200, 'height' => 1200, 'upscale' => FALSE]],
        ['id' => 'image_convert', 'data' => ['extension' => 'webp']],
      ],
    ],
  ];

  $created = [];
  foreach ($styles as $id => $def) {
    if (ImageStyle::load($id)) {
      continue;
    }
    $style = ImageStyle::create(['name' => $id, 'label' => $def['label']]);
    $weight = 1;
    foreach ($def['effects'] as $effect) {
      $effect['weight'] = $weight++;
      $style->addImageEffect($effect);
    }
    $style->save();
    $created[] = $id;
  }
  return $created ? 'Bildstile angelegt: ' . implode(', ', $created) : 'Bildstile bereits vorhanden.';
}

/**
 * Rolle „openhuus Redaktion“: Textseiten und Bilder pflegen, sonst nichts.
 */
function openhuus_structure_deploy_0003_rolle_redaktion(): string {
  $wanted = [
    'access toolbar',
    'view the administration theme',
    'access content overview',
    'view own unpublished content',
    'create page content',
    'edit any page content',
    'delete any page content',
    'access media overview',
    'create image media',
    'edit any image media',
    'delete any image media',
    'use text format basic_html',
  ];

  $available = array_keys(\Drupal::service('user.permissions')->getPermissions());
  $role = Role::load('openhuus_redaktion') ?? Role::create([
    'id' => 'openhuus_redaktion',
    'label' => 'openhuus Redaktion',
  ]);

  $missing = [];
  foreach ($wanted as $permission) {
    if (in_array($permission, $available, TRUE)) {
      $role->grantPermission($permission);
    }
    else {
      $missing[] = $permission;
    }
  }
  $role->save();

  return 'Rolle „openhuus Redaktion“ gespeichert.'
    . ($missing ? ' Noch nicht verfügbar (folgt später): ' . implode(', ', $missing) : '');
}

/**
 * Textformat „Basic HTML“ für Textseiten: Feld „Inhalt“ und Rollen.
 */
function openhuus_structure_deploy_0004_textformat(): string {
  if (!\Drupal\filter\Entity\FilterFormat::load('basic_html')) {
    return 'Textformat basic_html fehlt – zuerst das Rezept core/recipes/basic_html_format_editor anwenden.';
  }
  $done = [];
  if ($field = FieldConfig::loadByName('node', 'page', 'field_inhalt')) {
    $field->setSetting('allowed_formats', ['basic_html']);
    $field->save();
    $done[] = 'Feld „Inhalt“ nutzt Basic HTML';
  }
  foreach (['openhuus_redaktion', 'gaestehaus_admin'] as $rid) {
    if ($role = Role::load($rid)) {
      $role->grantPermission('use text format basic_html');
      $role->save();
      $done[] = "Recht für $rid";
    }
  }
  return implode('; ', $done) . '.';
}

/**
 * Startseite: Abschnitte, Bausteine, Einträge (Paragraphs) und Inhaltstyp.
 */
function openhuus_structure_deploy_0005_startseite(): string {
  \Drupal::moduleHandler()->loadInclude('openhuus_structure', 'inc', 'openhuus_structure.startseite');
  $neu = _openhuus_structure_startseite_anlegen();

  // Rechte: nur vergeben, was es bereits gibt (sonst verweigert Drupal das Speichern).
  $available = array_keys(\Drupal::service('user.permissions')->getPermissions());
  $fehlt = [];
  $rechte = [
    'openhuus_redaktion' => ['edit any startseite content'],
    'gaestehaus_admin' => ['create startseite content', 'edit any startseite content'],
  ];
  foreach ($rechte as $rid => $perms) {
    if ($role = Role::load($rid)) {
      foreach ($perms as $perm) {
        in_array($perm, $available, TRUE) ? $role->grantPermission($perm) : $fehlt[] = "$rid: $perm";
      }
      $role->save();
    }
  }

  return ($neu ? count($neu) . ' neu angelegt.' : 'Struktur bereits vorhanden.')
    . ($fehlt ? ' Rechte folgen beim nächsten Lauf: ' . implode(', ', $fehlt) : ' Rechte vergeben.');
}

/**
 * Startseite: Sprungmarken, Fahrzeit, Etikett, Statuszeile; Rechte nachziehen.
 */
function openhuus_structure_deploy_0006_startseite_ergaenzungen(): string {
  \Drupal::moduleHandler()->loadInclude('openhuus_structure', 'inc', 'openhuus_structure.startseite');
  $speicher = _openhuus_structure_speicher();
  if ($storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('paragraph', 'field_oh_variante')) {
    $storage->setSetting('allowed_values', $speicher['field_oh_variante'][1]['allowed_values']);
    $storage->save();
  }
  $neu = _openhuus_structure_startseite_anlegen();

  $available = array_keys(\Drupal::service('user.permissions')->getPermissions());
  $fehlt = [];
  $rechte = [
    'openhuus_redaktion' => ['edit any startseite content'],
    'gaestehaus_admin' => ['create startseite content', 'edit any startseite content'],
  ];
  foreach ($rechte as $rid => $perms) {
    if ($role = Role::load($rid)) {
      foreach ($perms as $perm) {
        in_array($perm, $available, TRUE) ? $role->grantPermission($perm) : $fehlt[] = "$rid: $perm";
      }
      $role->save();
    }
  }
  return ($neu ? 'Neu: ' . implode(', ', $neu) . '.' : 'Keine neuen Elemente.')
    . ($fehlt ? ' Fehlende Rechte: ' . implode(', ', $fehlt) : ' Rechte vergeben.');
}
