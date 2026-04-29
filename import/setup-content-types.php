<?php

/**
 * Setup script: Creates content types, fields, and taxonomies for The Athenaeum.
 * Run with: ddev drush php:script import/setup-content-types.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

// ============================================================
// HELPER FUNCTIONS
// ============================================================

function ensure_field_storage(string $entity_type, string $field_name, string $type, array $settings = []): void {
  if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
    $config = array_merge([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => $type,
    ], $settings);
    FieldStorageConfig::create($config)->save();
    echo "  [storage] $field_name ($type)\n";
  }
}

function ensure_field_instance(string $entity_type, string $bundle, string $field_name, string $label, array $settings = []): void {
  if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
    $config = array_merge([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
    ], $settings);
    FieldConfig::create($config)->save();
    echo "  [field] $bundle.$field_name: $label\n";
  }
}

// ============================================================
// TAXONOMIES
// ============================================================

$vocabularies = [
  'topics' => [
    'label' => 'Topics',
    'hierarchy' => TRUE,
    'description' => 'Subject areas mapped from Wikipedia categories',
    'terms' => [
      'Science' => ['Physics', 'Chemistry', 'Biology', 'Astronomy', 'Earth Sciences', 'Computer Science'],
      'History' => ['Ancient History', 'Medieval History', 'Early Modern History', 'Modern History', 'Military History', 'Social History'],
      'Geography' => ['Africa', 'Americas', 'Asia', 'Europe', 'Oceania', 'Polar Regions', 'Oceans'],
      'Biography' => ['Scientists & Inventors', 'Political Leaders', 'Artists & Writers', 'Explorers', 'Religious Figures', 'Military Figures'],
      'Arts' => ['Visual Arts', 'Music', 'Literature', 'Architecture', 'Film', 'Theater & Dance'],
      'Technology' => ['Computing', 'Aviation & Space', 'Transportation', 'Medicine & Health', 'Energy'],
      'Nature' => ['Animals', 'Plants & Fungi', 'Ecosystems', 'Evolution', 'Geology', 'Meteorology'],
      'Society' => ['Politics & Government', 'Economics', 'Law', 'Religion & Philosophy', 'Culture & Society', 'Education'],
      'Sports' => ['Individual Sports', 'Team Sports', 'Combat Sports', 'Racing', 'Olympic Sports'],
      'Military' => ['Battles & Wars', 'Weapons & Technology', 'Military Leaders', 'Strategy & Tactics'],
      'Philosophy' => ['Ancient Philosophy', 'Ethics', 'Epistemology', 'Logic', 'Philosophy of Science'],
      'Religion' => ['Christianity', 'Islam', 'Judaism', 'Buddhism', 'Hinduism', 'Other Religions'],
      'Mathematics' => ['Pure Mathematics', 'Applied Mathematics', 'Statistics', 'Mathematical Logic'],
      'Medicine' => ['Diseases & Conditions', 'Treatments & Surgery', 'Anatomy', 'Pharmacology', 'Epidemics'],
      'Engineering' => ['Civil Engineering', 'Mechanical Engineering', 'Electrical Engineering', 'Chemical Engineering'],
    ],
  ],
  'era' => [
    'label' => 'Era',
    'hierarchy' => FALSE,
    'description' => 'Temporal classification of article subjects',
    'terms' => [
      'Ancient (before 500 CE)' => [],
      'Medieval (500–1500)' => [],
      'Early Modern (1500–1800)' => [],
      'Modern (1800–1945)' => [],
      'Contemporary (1945–present)' => [],
      'Timeless' => [],
    ],
  ],
  'region' => [
    'label' => 'Region',
    'hierarchy' => FALSE,
    'description' => 'Geographic classification of article subjects',
    'terms' => [
      'Africa' => [],
      'Americas' => [],
      'Antarctica' => [],
      'Asia' => [],
      'Europe' => [],
      'Oceania' => [],
      'Global / Multiple Regions' => [],
      'Space' => [],
      'Not Geographic' => [],
    ],
  ],
];

$term_ids = [];

foreach ($vocabularies as $machine_name => $info) {
  if (!Vocabulary::load($machine_name)) {
    Vocabulary::create([
      'vid' => $machine_name,
      'name' => $info['label'],
      'description' => $info['description'],
      'hierarchy' => $info['hierarchy'] ? 1 : 0,
    ])->save();
    echo "Created vocabulary: {$info['label']}\n";
  } else {
    echo "Vocabulary exists: {$info['label']}\n";
  }

  foreach ($info['terms'] as $parent_name => $children) {
    $existing = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['name' => $parent_name, 'vid' => $machine_name]);
    if (empty($existing)) {
      $parent_term = Term::create([
        'vid' => $machine_name,
        'name' => $parent_name,
        'langcode' => 'en',
      ]);
      $parent_term->save();
      $term_ids[$machine_name][$parent_name] = $parent_term->id();
      echo "  + $parent_name\n";
    } else {
      $parent_term = reset($existing);
      $term_ids[$machine_name][$parent_name] = $parent_term->id();
    }

    foreach ($children as $child_name) {
      $existing_child = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $child_name, 'vid' => $machine_name]);
      if (empty($existing_child)) {
        Term::create([
          'vid' => $machine_name,
          'name' => $child_name,
          'parent' => [$term_ids[$machine_name][$parent_name]],
          'langcode' => 'en',
        ])->save();
        echo "    + $child_name\n";
      }
    }
  }
}

// ============================================================
// CONTENT TYPE: Featured Article
// ============================================================

if (!NodeType::load('featured_article')) {
  NodeType::create([
    'type' => 'featured_article',
    'name' => 'Featured Article',
    'description' => 'A Wikipedia Featured Article — the highest quality entries in the encyclopedia.',
  ])->save();
  echo "\nCreated content type: Featured Article\n";
} else {
  echo "\nContent type exists: Featured Article\n";
}

$node_type = 'node';
$bundle = 'featured_article';

// Lead section / summary
ensure_field_storage($node_type, 'field_lead', 'text_long');
ensure_field_instance($node_type, $bundle, 'field_lead', 'Lead / Summary', [
  'description' => 'Opening paragraph — the article summary.',
  'required' => FALSE,
]);

// Featured date on Wikipedia
ensure_field_storage($node_type, 'field_featured_date', 'datetime', [
  'settings' => ['datetime_type' => 'date'],
]);
ensure_field_instance($node_type, $bundle, 'field_featured_date', 'Featured Date', [
  'description' => 'Date this article was featured on Wikipedia.',
]);

// Word count
ensure_field_storage($node_type, 'field_word_count', 'integer');
ensure_field_instance($node_type, $bundle, 'field_word_count', 'Word Count');

// Reference count
ensure_field_storage($node_type, 'field_reference_count', 'integer');
ensure_field_instance($node_type, $bundle, 'field_reference_count', 'Reference Count', [
  'description' => 'Number of citations in the original Wikipedia article.',
]);

// Coordinates
ensure_field_storage($node_type, 'field_coordinates', 'geofield');
ensure_field_instance($node_type, $bundle, 'field_coordinates', 'Coordinates', [
  'description' => 'Geographic coordinates for location-based articles.',
  'required' => FALSE,
]);

// Original Wikipedia URL (required for CC BY-SA)
ensure_field_storage($node_type, 'field_original_url', 'link');
ensure_field_instance($node_type, $bundle, 'field_original_url', 'Original Wikipedia Article', [
  'description' => 'Link to the original Wikipedia article (required for CC BY-SA 4.0).',
  'required' => TRUE,
  'settings' => ['link_type' => 16, 'title' => 0],
]);

// Lead image
ensure_field_storage($node_type, 'field_image', 'image', [
  'settings' => ['uri_scheme' => 'public', 'default_image' => []],
]);
ensure_field_instance($node_type, $bundle, 'field_image', 'Lead Image', [
  'description' => 'Lead image from Wikimedia Commons.',
  'required' => FALSE,
]);

// Image credit / license
ensure_field_storage($node_type, 'field_image_credit', 'string_long');
ensure_field_instance($node_type, $bundle, 'field_image_credit', 'Image Credit & License', [
  'description' => 'Attribution and license information for the lead image.',
]);

// Topics taxonomy (multi-value)
ensure_field_storage($node_type, 'field_topics', 'entity_reference', [
  'settings' => ['target_type' => 'taxonomy_term'],
  'cardinality' => -1,
]);
ensure_field_instance($node_type, $bundle, 'field_topics', 'Topics', [
  'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['topics' => 'topics']]],
]);

// Era taxonomy
ensure_field_storage($node_type, 'field_era', 'entity_reference', [
  'settings' => ['target_type' => 'taxonomy_term'],
]);
ensure_field_instance($node_type, $bundle, 'field_era', 'Era', [
  'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['era' => 'era']]],
]);

// Region taxonomy
ensure_field_storage($node_type, 'field_region', 'entity_reference', [
  'settings' => ['target_type' => 'taxonomy_term'],
]);
ensure_field_instance($node_type, $bundle, 'field_region', 'Region', [
  'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['region' => 'region']]],
]);

// Related articles (entity reference, multi-value)
ensure_field_storage($node_type, 'field_related_articles', 'entity_reference', [
  'settings' => ['target_type' => 'node'],
  'cardinality' => -1,
]);
ensure_field_instance($node_type, $bundle, 'field_related_articles', 'Related Articles', [
  'description' => 'Cross-references from Wikipedia "See also" section.',
  'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['featured_article' => 'featured_article']]],
]);

// Infobox data (serialized key-value pairs)
ensure_field_storage($node_type, 'field_infobox_data', 'string_long');
ensure_field_instance($node_type, $bundle, 'field_infobox_data', 'Infobox Data', [
  'description' => 'JSON-serialized infobox key-value pairs.',
]);

// "Why this matters" AI enrichment blurb
ensure_field_storage($node_type, 'field_why_it_matters', 'text_long');
ensure_field_instance($node_type, $bundle, 'field_why_it_matters', 'Why This Matters', [
  'description' => 'AI-generated 2-3 sentence summary of significance.',
]);

// Wikipedia article revision ID (for deduplication during import)
ensure_field_storage($node_type, 'field_wikipedia_pageid', 'integer');
ensure_field_instance($node_type, $bundle, 'field_wikipedia_pageid', 'Wikipedia Page ID', [
  'description' => 'Wikipedia page ID for deduplication and resume support.',
]);

echo "\nFeatured Article content type configured.\n";

// ============================================================
// CONTENT TYPE: Topic Landing Page
// ============================================================

if (!NodeType::load('topic_landing_page')) {
  NodeType::create([
    'type' => 'topic_landing_page',
    'name' => 'Topic Landing Page',
    'description' => 'Auto-generated hub page for a top-level topic area.',
  ])->save();
  echo "\nCreated content type: Topic Landing Page\n";
} else {
  echo "\nContent type exists: Topic Landing Page\n";
}

$bundle = 'topic_landing_page';

ensure_field_storage($node_type, 'field_topic', 'entity_reference', [
  'settings' => ['target_type' => 'taxonomy_term'],
]);
ensure_field_instance($node_type, $bundle, 'field_topic', 'Topic', [
  'description' => 'The taxonomy term this landing page represents.',
  'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['topics' => 'topics']]],
]);

echo "\nTopic Landing Page content type configured.\n";

// ============================================================
// PATHAUTO PATTERNS
// ============================================================

$pattern_storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');

if (!$pattern_storage->load('featured_article')) {
  $pattern_storage->create([
    'id' => 'featured_article',
    'label' => 'Featured Articles',
    'type' => 'canonical_entities:node',
    'pattern' => '/article/[node:title]',
    'selection_criteria' => [
      'node_type' => [
        'id' => 'entity_bundle:node',
        'negate' => FALSE,
        'context_mapping' => ['node' => 'node'],
        'bundles' => ['featured_article' => 'featured_article'],
      ],
    ],
    'weight' => 0,
    'status' => TRUE,
  ])->save();
  echo "\nCreated pathauto pattern for Featured Articles.\n";
}

if (!$pattern_storage->load('topic_landing_page')) {
  $pattern_storage->create([
    'id' => 'topic_landing_page',
    'label' => 'Topic Landing Pages',
    'type' => 'canonical_entities:node',
    'pattern' => '/topic/[node:title]',
    'selection_criteria' => [
      'node_type' => [
        'id' => 'entity_bundle:node',
        'negate' => FALSE,
        'context_mapping' => ['node' => 'node'],
        'bundles' => ['topic_landing_page' => 'topic_landing_page'],
      ],
    ],
    'weight' => 0,
    'status' => TRUE,
  ])->save();
  echo "\nCreated pathauto pattern for Topic Landing Pages.\n";
}

echo "\n=== Setup complete ===\n";
