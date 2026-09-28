<?php

/**
 * @file
 * Struktur der Startseite: Abschnitte, Bausteine und Einträge (Paragraphs).
 *
 * Alles wird über die Entity API angelegt und ist idempotent: vorhandene
 * Typen und Felder bleiben unverändert, fehlende werden ergänzt.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Symbole zur Auswahl (Schlüssel = Name im Lucide-Symbolsatz).
 */
function _openhuus_structure_symbole(): array {
  return [
    'home' => 'Haus',
    'users' => 'Gemeinschaft',
    'brain' => 'Konzentration',
    'leaf' => 'Natur',
    'keyboard' => 'Arbeiten',
    'bed-double' => 'Schlafen',
    'party-popper' => 'Feiern',
    'zap' => 'Glasfaser / Strom',
    'wifi' => 'WLAN',
    'telescope' => 'Teleskop',
    'sun' => 'Sonne / Solar',
    'flame' => 'Kaminfeuer',
    'star' => 'Stern',
    'coffee' => 'Kaffee',
    'train-front' => 'Bahn',
    'bus' => 'Bus',
    'car' => 'Auto',
    'bike' => 'Fahrrad',
    'waves' => 'Wasser / See',
    'footprints' => 'Wandern',
    'castle' => 'Schloss',
    'landmark' => 'Altstadt / Bauwerk',
    'church' => 'Kirche',
    'drama' => 'Kultur',
    'bird' => 'Vogel',
    'sunset' => 'Sonnenuntergang',
    'map-pin' => 'Ort',
    'info' => 'Hinweis',
  ];
}

/**
 * Abschnitte (direkt auf der Startseite) und Bausteine (im Textabschnitt).
 */
function _openhuus_structure_abschnitte(): array {
  return ['oh_hero', 'oh_leiste', 'oh_vormerk', 'oh_textabschnitt', 'oh_schluss'];
}

function _openhuus_structure_bausteine(): array {
  return ['oh_kennzahlen', 'oh_karten', 'oh_angebote', 'oh_hinweisbox', 'oh_stichworte', 'oh_ausfluege', 'oh_liste', 'oh_zitat', 'oh_galerie'];
}

/**
 * Feldspeicher: Name => [Typ, Einstellungen, Kardinalität].
 */
function _openhuus_structure_speicher(): array {
  return [
    'field_oh_kicker' => ['string'],
    'field_oh_titel' => ['string'],
    'field_oh_hervorhebung' => ['string'],
    'field_oh_text' => ['text_long'],
    'field_oh_text_kurz' => ['string_long'],
    'field_oh_hinweis' => ['string'],
    'field_oh_ort' => ['string'],
    'field_oh_wert' => ['string'],
    'field_oh_anker' => ['string'],
    'field_oh_countdown' => ['boolean'],
    'field_oh_symbol' => ['list_string', ['allowed_values' => _openhuus_structure_symbole()]],
    'field_oh_farbe' => ['list_string', ['allowed_values' => [
      'schiefer' => 'Schiefer', 'senf' => 'Senf', 'blau' => 'Blau', 'gruen' => 'Grün',
    ]]],
    'field_oh_hintergrund' => ['list_string', ['allowed_values' => [
      'hell' => 'Hell (weiß)', 'getoent' => 'Getönt (hellgrau)', 'dunkel' => 'Dunkel (Schiefer)', 'nacht' => 'Nacht (dunkelblau)',
    ]]],
    'field_oh_variante' => ['list_string', ['allowed_values' => [
      'normal' => 'Normal', 'hervorgehoben' => 'Hervorgehoben (Senf)', 'dunkel' => 'Dunkel', 'status' => 'Statuszeile (grüner Punkt)',
    ]]],
    'field_oh_link' => ['link'],
    'field_oh_link2' => ['link'],
    'field_oh_bild' => ['entity_reference', ['target_type' => 'media']],
    'field_oh_bilder' => ['entity_reference', ['target_type' => 'media'], -1],
    'field_oh_bausteine' => ['entity_reference_revisions', ['target_type' => 'paragraph'], -1],
    'field_oh_eintraege' => ['entity_reference_revisions', ['target_type' => 'paragraph'], -1],
  ];
}

/**
 * Absatztypen: ID => [Bezeichnung, Beschreibung, [Feld => [Bezeichnung, Optionen]]].
 */
function _openhuus_structure_typen(): array {
  $pflicht = ['required' => TRUE];
  return [
    // Abschnitte.
    'oh_hero' => ['Hero', 'Großer Einstieg ganz oben', [
      'field_oh_kicker' => ['Kicker (kleine Zeile über dem Titel)'],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_hervorhebung' => ['Hervorhebung (kursive zweite Zeile)'],
      'field_oh_text_kurz' => ['Untertitel'],
      'field_oh_ort' => ['Ort'],
      'field_oh_countdown' => ['Countdown bis zur Eröffnung anzeigen', ['default' => 1]],
      'field_oh_link' => ['Button 1'],
      'field_oh_link2' => ['Button 2'],
      'field_oh_bild' => ['Hintergrundbild'],
    ]],
    'oh_leiste' => ['Hinweisleiste', 'Schmale Leiste, z. B. Berlin – Karrenzin – Hamburg', [
      'field_oh_eintraege' => ['Stationen', ['targets' => ['oh_station']]],
    ]],
    'oh_vormerk' => ['Vormerkliste', 'Abschnitt mit dem Formular zur Vormerkliste', [
      'field_oh_kicker' => ['Kicker'],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_text_kurz' => ['Text'],
      'field_oh_hinweis' => ['Hinweis unter dem Formular'],
      'field_oh_anker' => ['Sprungmarke (z. B. vormerk)'],
    ]],
    'oh_textabschnitt' => ['Textabschnitt', 'Überschrift, Text und beliebige Bausteine', [
      'field_oh_kicker' => ['Kicker'],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_hervorhebung' => ['Hervorhebung (kursive zweite Zeile)'],
      'field_oh_text' => ['Text'],
      'field_oh_hintergrund' => ['Hintergrund', ['default' => 'hell', 'required' => TRUE]],
      'field_oh_anker' => ['Sprungmarke (z. B. haus – für Links wie #haus)'],
      'field_oh_link' => ['Link (z. B. „Route planen“ oder „Jetzt buchen“)'],
      'field_oh_bausteine' => ['Bausteine', ['targets' => _openhuus_structure_bausteine()]],
    ]],
    'oh_schluss' => ['Schlussaufruf', 'Abschluss mit Button, z. B. Eröffnungstermin', [
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_text_kurz' => ['Text'],
      'field_oh_link' => ['Button'],
    ]],

    // Bausteine.
    'oh_kennzahlen' => ['Kennzahlen', 'Kästchen mit Zahl oder Symbol, z. B. 9 Gästezimmer', [
      'field_oh_eintraege' => ['Kennzahlen', ['targets' => ['oh_kennzahl']]],
    ]],
    'oh_karten' => ['Karten', 'Karten mit Symbol, Titel und Text', [
      'field_oh_eintraege' => ['Karten', ['targets' => ['oh_karte']]],
    ]],
    'oh_angebote' => ['Angebote', 'Angebotskacheln mit farbigem Symbol', [
      'field_oh_eintraege' => ['Angebote', ['targets' => ['oh_angebot']]],
    ]],
    'oh_hinweisbox' => ['Hinweisbox', 'Kasten mit Symbol, z. B. Kaminöfen oder Rufbus', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_titel' => ['Titel'],
      'field_oh_text_kurz' => ['Text'],
      'field_oh_variante' => ['Darstellung', ['default' => 'normal', 'required' => TRUE]],
    ]],
    'oh_stichworte' => ['Stichworte', 'Kleine Etiketten, z. B. SUP, Wandern', [
      'field_oh_eintraege' => ['Stichworte', ['targets' => ['oh_stichwort']]],
    ]],
    'oh_ausfluege' => ['Ausflugsziele', 'Raster mit Zielen und Fahrzeit', [
      'field_oh_eintraege' => ['Ausflugsziele', ['targets' => ['oh_ausflug']]],
    ]],
    'oh_liste' => ['Liste mit Symbolen', 'Einträge untereinander, z. B. Anreise', [
      'field_oh_eintraege' => ['Einträge', ['targets' => ['oh_listeneintrag']]],
    ]],
    'oh_zitat' => ['Zitat', 'Hervorgehobenes Zitat', [
      'field_oh_text_kurz' => ['Zitat', $pflicht],
    ]],
    'oh_galerie' => ['Galerie', 'Bildergalerie mit Großansicht', [
      'field_oh_titel' => ['Titel (optional)'],
      'field_oh_bilder' => ['Bilder', $pflicht],
    ]],

    // Einträge.
    'oh_station' => ['Station', 'Eintrag der Hinweisleiste', [
      'field_oh_titel' => ['Name', $pflicht],
      'field_oh_wert' => ['Fahrzeit (z. B. 2 Std.)'],
    ]],
    'oh_kennzahl' => ['Kennzahl', 'Eintrag der Kennzahlen', [
      'field_oh_wert' => ['Wert (z. B. 9 oder 400)'],
      'field_oh_symbol' => ['… oder Symbol statt Wert'],
      'field_oh_titel' => ['Bezeichnung', $pflicht],
    ]],
    'oh_karte' => ['Karte', 'Eintrag der Karten', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_text_kurz' => ['Text'],
    ]],
    'oh_angebot' => ['Angebot', 'Eintrag der Angebote', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_farbe' => ['Farbe des Symbols', ['default' => 'schiefer', 'required' => TRUE]],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_text_kurz' => ['Text'],
      'field_oh_hinweis' => ['Etikett (z. B. Montag – Freitag)'],
      'field_oh_link' => ['Link (optional)'],
    ]],
    'oh_stichwort' => ['Stichwort', 'Eintrag der Stichworte', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_titel' => ['Text', $pflicht],
    ]],
    'oh_ausflug' => ['Ausflugsziel', 'Eintrag der Ausflugsziele', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_titel' => ['Ziel', $pflicht],
      'field_oh_text_kurz' => ['Beschreibung und Fahrzeit'],
    ]],
    'oh_listeneintrag' => ['Listeneintrag', 'Eintrag der Liste mit Symbolen', [
      'field_oh_symbol' => ['Symbol'],
      'field_oh_titel' => ['Titel', $pflicht],
      'field_oh_text_kurz' => ['Text'],
    ]],
  ];
}

function _openhuus_structure_widget(string $type, string $title = 'Eintrag'): array {
  return match ($type) {
    'string' => ['string_textfield', ['size' => 60, 'placeholder' => '']],
    'string_long' => ['string_textarea', ['rows' => 3, 'placeholder' => '']],
    'text_long' => ['text_textarea', ['rows' => 6, 'placeholder' => '']],
    'list_string' => ['options_select', []],
    'boolean' => ['boolean_checkbox', ['display_label' => TRUE]],
    'link' => ['link_default', ['placeholder_url' => '', 'placeholder_title' => '']],
    'entity_reference' => ['media_library_widget', ['media_types' => []]],
    'entity_reference_revisions' => ['paragraphs', [
      'title' => $title,
      'title_plural' => $title,
      'edit_mode' => 'closed',
      'closed_mode' => 'summary',
      'autocollapse' => 'none',
      'closed_mode_threshold' => 0,
      'add_mode' => 'dropdown',
      'form_display_mode' => 'default',
      'default_paragraph_type' => '_none',
      'features' => ['duplicate' => 'duplicate', 'collapse_edit_all' => 'collapse_edit_all'],
    ]],
  };
}

function _openhuus_structure_formatter(string $type): string {
  return match ($type) {
    'string' => 'string',
    'string_long' => 'basic_string',
    'text_long' => 'text_default',
    'list_string' => 'list_key',
    'boolean' => 'boolean',
    'link' => 'link',
    'entity_reference' => 'entity_reference_entity_view',
    'entity_reference_revisions' => 'entity_reference_revisions_entity_view',
  };
}

function _openhuus_structure_speicher_anlegen(string $entity_type, string $name, array $def): bool {
  if (FieldStorageConfig::loadByName($entity_type, $name)) {
    return FALSE;
  }
  FieldStorageConfig::create([
    'field_name' => $name,
    'entity_type' => $entity_type,
    'type' => $def[0],
    'settings' => $def[1] ?? [],
    'cardinality' => $def[2] ?? 1,
  ])->save();
  return TRUE;
}

function _openhuus_structure_felder_anlegen(string $entity_type, string $bundle, array $fields, array $speicher, string $widget_title = 'Eintrag'): void {
  $repo = \Drupal::service('entity_display.repository');
  $form = $repo->getFormDisplay($entity_type, $bundle, 'default');
  $view = $repo->getViewDisplay($entity_type, $bundle, 'default');
  $weight = 10;

  foreach ($fields as $name => $def) {
    $label = $def[0];
    $opt = $def[1] ?? [];
    $type = $speicher[$name][0];

    if (!FieldConfig::loadByName($entity_type, $bundle, $name)) {
      $settings = match ($type) {
        'entity_reference_revisions' => [
          'handler' => 'default:paragraph',
          'handler_settings' => [
            'target_bundles' => array_combine($opt['targets'], $opt['targets']),
            'negate' => 0,
          ],
        ],
        'entity_reference' => [
          'handler' => 'default:media',
          'handler_settings' => ['target_bundles' => ['image' => 'image']],
        ],
        'text_long' => ['allowed_formats' => ['basic_html']],
        'link' => ['link_type' => 17, 'title' => 1],
        default => [],
      };
      $values = [
        'field_name' => $name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'label' => $label,
        'required' => !empty($opt['required']),
        'settings' => $settings,
      ];
      if (isset($opt['default'])) {
        $values['default_value'] = [['value' => $opt['default']]];
      }
      FieldConfig::create($values)->save();
    }

    [$widget, $widget_settings] = _openhuus_structure_widget($type, $widget_title);
    $form->setComponent($name, ['type' => $widget, 'weight' => $weight, 'settings' => $widget_settings]);
    $view->setComponent($name, ['type' => _openhuus_structure_formatter($type), 'label' => 'hidden', 'weight' => $weight]);
    $weight++;
  }
  $form->save();
  $view->save();
}

/**
 * Legt die gesamte Startseiten-Struktur an. Gibt eine Liste des Neuen zurück.
 */
function _openhuus_structure_startseite_anlegen(): array {
  $neu = [];
  $speicher = _openhuus_structure_speicher();
  $typen = _openhuus_structure_typen();

  // 1. Absatztypen zuerst, damit Verweise auf sie gültig sind.
  foreach ($typen as $id => $typ) {
    if (!ParagraphsType::load($id)) {
      ParagraphsType::create(['id' => $id, 'label' => $typ[0], 'description' => $typ[1]])->save();
      $neu[] = $id;
    }
  }

  // 2. Feldspeicher für Absätze.
  foreach ($speicher as $name => $def) {
    if (_openhuus_structure_speicher_anlegen('paragraph', $name, $def)) {
      $neu[] = $name;
    }
  }

  // 3. Felder, Formular und Anzeige je Absatztyp.
  foreach ($typen as $id => $typ) {
    _openhuus_structure_felder_anlegen('paragraph', $id, $typ[2], $speicher);
  }

  // 4. Inhaltstyp „Startseite“ mit dem Feld „Abschnitte“.
  if (!NodeType::load('startseite')) {
    NodeType::create([
      'type' => 'startseite',
      'name' => 'Startseite',
      'description' => 'Die Startseite von openhuus, aus Abschnitten zusammengesetzt.',
      'display_submitted' => FALSE,
      'new_revision' => TRUE,
    ])->save();
    $neu[] = 'Inhaltstyp startseite';
  }
  $node_speicher = ['field_abschnitte' => ['entity_reference_revisions', ['target_type' => 'paragraph'], -1]];
  if (_openhuus_structure_speicher_anlegen('node', 'field_abschnitte', $node_speicher['field_abschnitte'])) {
    $neu[] = 'field_abschnitte';
  }
  _openhuus_structure_felder_anlegen('node', 'startseite', [
    'field_abschnitte' => ['Abschnitte', ['targets' => _openhuus_structure_abschnitte()]],
  ], $node_speicher, 'Abschnitt');

  return $neu;
}
