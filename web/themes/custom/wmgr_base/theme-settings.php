<?php

/**
 * @file
 * Functions to support Olivero theme settings.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for system_theme_settings.
 */
function wmgr_base_form_system_theme_settings_alter(&$form, FormStateInterface $form_state): void {
  $form['#attached']['library'][] = 'wmgr_base/color-picker';

  $color_config = [
    'colors' => [
      'base_primary_color' => t('Primary base color'),
    ],
    'schemes' => [
      'default' => [
        'label' => 'Blue Lagoon',
        'colors' => [
          'base_primary_color' => '#1b9ae4',
        ],
      ],
      'firehouse' => [
        'label' => 'Firehouse',
        'colors' => [
          'base_primary_color' => '#a30f0f',
        ],
      ],
      'ice' => [
        'label' => 'Ice',
        'colors' => [
          'base_primary_color' => '#57919e',
        ],
      ],
      'plum' => [
        'label' => 'Plum',
        'colors' => [
          'base_primary_color' => '#7a4587',
        ],
      ],
      'slate' => [
        'label' => 'Slate',
        'colors' => [
          'base_primary_color' => '#47625b',
        ],
      ],
    ],
  ];

  $form['#attached']['drupalSettings']['wmgr_base']['colorSchemes'] = $color_config['schemes'];

  $form['wmgr_base_settings']['wmgr_base_utilities'] = [
    '#type' => 'fieldset',
    '#title' => t('Olivero Utilities'),
  ];
  $form['wmgr_base_settings']['wmgr_base_utilities']['mobile_menu_all_widths'] = [
    '#type' => 'checkbox',
    '#title' => t('Enable mobile menu at all widths'),
    '#config_target' => 'wmgr_base.settings:mobile_menu_all_widths',
    '#description' => t('Enables the mobile menu toggle at all widths.'),
  ];
  $form['wmgr_base_settings']['wmgr_base_utilities']['site_branding_bg_color'] = [
    '#type' => 'select',
    '#title' => t('Header site branding background color'),
    '#options' => [
      'default' => t('Primary Branding Color'),
      'gray' => t('Gray'),
      'white' => t('White'),
    ],
    '#config_target' => 'wmgr_base.settings:site_branding_bg_color',
  ];
  $form['wmgr_base_settings']['wmgr_base_utilities']['wmgr_base_color_scheme'] = [
    '#type' => 'fieldset',
    '#title' => t('Olivero Color Scheme Settings'),
  ];
  $form['wmgr_base_settings']['wmgr_base_utilities']['wmgr_base_color_scheme']['description'] = [
    '#type' => 'html_tag',
    '#tag' => 'p',
    '#value' => t('These settings adjust the look and feel of the Olivero theme. Changing the color below will change the base hue, saturation, and lightness values the Olivero theme uses to determine its internal colors.'),
  ];
  $form['wmgr_base_settings']['wmgr_base_utilities']['wmgr_base_color_scheme']['color_scheme'] = [
    '#type' => 'select',
    '#title' => t('Olivero Color Scheme'),
    '#empty_option' => t('Custom'),
    '#empty_value' => '',
    '#options' => [
      'default' => t('Blue Lagoon (Default)'),
      'firehouse' => t('Firehouse'),
      'ice' => t('Ice'),
      'plum' => t('Plum'),
      'slate' => t('Slate'),
    ],
    '#input' => FALSE,
    '#wrapper_attributes' => [
      'style' => 'display:none;',
    ],
  ];

  foreach ($color_config['colors'] as $key => $title) {
    $form['wmgr_base_settings']['wmgr_base_utilities']['wmgr_base_color_scheme'][$key] = [
      '#type' => 'textfield',
      '#maxlength' => 7,
      '#size' => 10,
      '#title' => $title,
      '#description' => t('Enter color in hexadecimal format (#abc123).') . '<br/>' . t('Derivatives will be formed from this color.'),
      '#config_target' => "wmgr_base.settings:$key",
      '#attributes' => [
        // Regex copied from Color::validateHex()
        'pattern' => '^[#]?([0-9a-fA-F]{3}){1,2}$',
      ],
      '#wrapper_attributes' => [
        'data-drupal-selector' => 'wmgr_base-color-picker',
      ],
    ];
  }
}
