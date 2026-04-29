<?php

/**
 * Setup Scolta Search API server and index for The Athenaeum.
 * Run with: ddev drush php:script import/setup-scolta.php
 */

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\Item\Field;

// ============================================================
// CREATE SCOLTA SEARCH API SERVER
// ============================================================

if (!Server::load('athenaeum_scolta')) {
  $server = Server::create([
    'id' => 'athenaeum_scolta',
    'name' => 'Athenaeum Scolta',
    'description' => 'Scolta semantic search server for The Athenaeum encyclopedia.',
    'backend' => 'scolta_pagefind',
    'backend_config' => [],
    'status' => TRUE,
  ]);
  $server->save();
  echo "Created Search API server: Athenaeum Scolta\n";
} else {
  echo "Server already exists: Athenaeum Scolta\n";
}

$server = Server::load('athenaeum_scolta');

// ============================================================
// CREATE SEARCH INDEX
// ============================================================

if (!Index::load('featured_articles')) {
  $index = Index::create([
    'id' => 'featured_articles',
    'name' => 'Featured Articles',
    'description' => 'Index of all Wikipedia Featured Articles for Scolta search.',
    'server' => 'athenaeum_scolta',
    'datasource_settings' => [
      'entity:node' => [
        'plugin_id' => 'entity:node',
        'settings' => [
          'bundles' => [
            'default' => FALSE,
            'selected' => ['featured_article'],
          ],
          'languages' => [
            'default' => TRUE,
            'selected' => [],
          ],
        ],
      ],
    ],
    'tracker_settings' => [
      'default' => [
        'plugin_id' => 'default',
        'settings' => [],
      ],
    ],
    'options' => [
      'index_directly' => FALSE,
      'cron_limit' => 100,
    ],
    'status' => TRUE,
  ]);
  $index->save();
  echo "Created Search API index: Featured Articles\n";
} else {
  echo "Index already exists: Featured Articles\n";
}

$index = Index::load('featured_articles');

// ============================================================
// ADD INDEX FIELDS
// ============================================================

$fieldsToAdd = [
  'title' => ['label' => 'Title', 'type' => 'text', 'boost' => '5.0', 'datasource_id' => 'entity:node', 'property_path' => 'title'],
  'field_lead' => ['label' => 'Lead / Summary', 'type' => 'text', 'boost' => '3.0', 'datasource_id' => 'entity:node', 'property_path' => 'field_lead'],
  'body' => ['label' => 'Body', 'type' => 'text', 'boost' => '1.0', 'datasource_id' => 'entity:node', 'property_path' => 'body'],
  'field_topics' => ['label' => 'Topics', 'type' => 'string', 'boost' => '2.0', 'datasource_id' => 'entity:node', 'property_path' => 'field_topics:entity:name'],
  'field_era' => ['label' => 'Era', 'type' => 'string', 'boost' => '1.0', 'datasource_id' => 'entity:node', 'property_path' => 'field_era:entity:name'],
  'field_region' => ['label' => 'Region', 'type' => 'string', 'boost' => '1.0', 'datasource_id' => 'entity:node', 'property_path' => 'field_region:entity:name'],
  'field_why_it_matters' => ['label' => 'Why It Matters', 'type' => 'text', 'boost' => '2.0', 'datasource_id' => 'entity:node', 'property_path' => 'field_why_it_matters'],
];

foreach ($fieldsToAdd as $fieldId => $fieldConfig) {
  if (!$index->getField($fieldId)) {
    $field = new Field($index, $fieldId);
    $field->setLabel($fieldConfig['label']);
    $field->setType($fieldConfig['type']);
    $field->setBoost((float) $fieldConfig['boost']);
    $field->setDatasourceId($fieldConfig['datasource_id']);
    $field->setPropertyPath($fieldConfig['property_path']);
    $index->addField($field);
    echo "  Added field: {$fieldConfig['label']}\n";
  }
}

$index->save();
echo "\nScolta index configured.\n";
echo "Run: ddev drush search-api:index && ddev drush scolta:build\n";
echo "=== Scolta setup complete ===\n";
