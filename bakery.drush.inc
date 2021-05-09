<?php

/**
 * @file
 * Drush integration for Bakery.
 */

/**
 * Implements hook_drush_help().
 */
function bakery_drush_help($section) {
  switch ($section) {
    case 'drush:bakery-add-minion':
      return dt('Update the Bakery configuration to add a minion site.');
  }
}

/**
 * Implements hook_drush_command().
 */
function bakery_drush_command() {
  $items['bakery-add-minion'] = array(
    'description' => 'Add a Bakery minion site.',
    'arguments' => array(
      'minion' => 'Minion site URL, starts with http and ending with /.',
    ),
  );
  return $items;
}

/**
 * For adding minion sites.
 */
function drush_bakery_add_minion($minion) {
  // @FIXME
  // Could not extract the default value because it is either indeterminate, or
  // not scalar. You'll need to provide a default value in
  // config/install/bakery.settings.yml and config/schema/bakery.schema.yml.
  $config = \Drupal::configFactory()->getEditable('bakery.settings');
  $minions = $config->get('bakery_minions');
  $minions[] = $minion;
  $config->set('bakery_minions', $minion)->save();
}
