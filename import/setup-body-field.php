<?php

/**
 * Add the body field to the featured_article content type.
 * Run with: ddev drush php:script import/setup-body-field.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

// Add body FieldConfig to featured_article (storage already exists via 'page').
$existing = FieldConfig::loadByName('node', 'featured_article', 'body');
if (!$existing) {
  FieldConfig::create([
    'field_name' => 'body',
    'entity_type' => 'node',
    'bundle' => 'featured_article',
    'label' => 'Body',
    'required' => FALSE,
    'settings' => [
      'display_summary' => FALSE,
    ],
  ])->save();
  echo "Added body field to featured_article.\n";
} else {
  echo "body field already configured for featured_article.\n";
}

// Add body to the default view display.
$display = EntityViewDisplay::load('node.featured_article.default');
if ($display) {
  $display->setComponent('body', [
    'weight' => 10,
    'label' => 'hidden',
    'type' => 'text_default',
  ]);
  $display->save();
  echo "Updated default display to show body.\n";
}

// Add body to search_index display mode (used by Scolta pagefind indexer).
$search_display = EntityViewDisplay::load('node.featured_article.search_index');
if (!$search_display) {
  $search_display = EntityViewDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'featured_article',
    'mode' => 'search_index',
    'status' => TRUE,
  ]);
}
$search_display->setComponent('body', ['weight' => 1, 'label' => 'hidden', 'type' => 'text_default']);
$search_display->setComponent('field_lead', ['weight' => 2, 'label' => 'hidden', 'type' => 'text_default']);
$search_display->setComponent('field_topics', ['weight' => 3, 'label' => 'hidden', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$search_display->setComponent('field_era', ['weight' => 4, 'label' => 'hidden', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$search_display->setComponent('field_region', ['weight' => 5, 'label' => 'hidden', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$search_display->setComponent('field_why_it_matters', ['weight' => 6, 'label' => 'hidden', 'type' => 'text_default']);
$search_display->save();
echo "Configured search_index display mode.\n";

echo "\n=== Body field setup complete ===\n";
