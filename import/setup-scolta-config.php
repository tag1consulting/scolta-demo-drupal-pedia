<?php

/**
 * Configure Scolta settings and place the search block.
 * Run with: ddev drush php:script import/setup-scolta-config.php
 */

use Drupal\block\Entity\Block;

// ============================================================
// SCOLTA SETTINGS
// ============================================================

$config = \Drupal::configFactory()->getEditable('scolta.settings');
$config->setData([
  'ai_provider' => 'anthropic',
  'ai_model' => 'claude-haiku-4-5-20251001',
  'ai_expansion_model' => '',
  'ai_base_url' => '',
  'ai_expand_query' => TRUE,
  'ai_summarize' => TRUE,
  'ai_languages' => ['en'],
  'max_follow_ups' => 3,
  'site_name' => 'The Athenaeum',
  'site_description' => 'Wikipedia\'s ~6,900 Featured Articles — the highest-quality encyclopedia entries spanning all domains of human knowledge. Search to discover unexpected connections across science, history, art, nature, and more.',
  'scoring' => [
    'title_match_boost' => 2.0,
    'title_all_terms_multiplier' => 1.5,
    'content_match_boost' => 0.5,
    'recency_boost_max' => 0.1,
    'recency_half_life_days' => 3650,
    'recency_penalty_after_days' => 36500,
    'recency_max_penalty' => 0.05,
    'expand_primary_weight' => 0.6,
    'language' => 'en',
    'custom_stop_words' => [],
    'recency_strategy' => 'exponential',
    'recency_curve' => [],
  ],
  'display' => [
    'excerpt_length' => 350,
    'results_per_page' => 12,
    'max_pagefind_results' => 60,
    'ai_summary_top_n' => 10,
    'ai_summary_max_chars' => 5000,
  ],
  'cache_ttl' => 2592000,
  'prompt_expand_query' => '',
  'prompt_summarize' => '',
  'prompt_follow_up' => '',
  'indexer' => 'auto',
  'memory_budget' => [
    'profile' => 'conservative',
    'custom_bytes' => NULL,
    'chunk_size' => NULL,
  ],
  'pagefind' => [
    'build_dir' => 'private://scolta-build',
    'output_dir' => 'public://scolta-pagefind',
    'binary' => 'pagefind',
    'auto_rebuild' => FALSE,
    'view_mode' => 'search_index',
  ],
]);
$config->save();
echo "Scolta settings configured.\n";

// Grant anonymous users access to Scolta search.
$anon_role = \Drupal::entityTypeManager()->getStorage('user_role')->load('anonymous');
if ($anon_role && !$anon_role->hasPermission('use scolta ai')) {
  $anon_role->grantPermission('use scolta ai');
  $anon_role->save();
  echo "Granted anonymous users 'use scolta ai' permission.\n";
}
$auth_role = \Drupal::entityTypeManager()->getStorage('user_role')->load('authenticated');
if ($auth_role && !$auth_role->hasPermission('use scolta ai')) {
  $auth_role->grantPermission('use scolta ai');
  $auth_role->save();
  echo "Granted authenticated users 'use scolta ai' permission.\n";
}

// ============================================================
// PLACE SCOLTA SEARCH BLOCK — full page at /search
// ============================================================

$block_id = 'athenaeum_scolta_search';
if (!Block::load($block_id)) {
  Block::create([
    'id' => $block_id,
    'theme' => 'athenaeum',
    'region' => 'content',
    'weight' => -20,
    'plugin' => 'scolta_search',
    'settings' => [
      'id' => 'scolta_search',
      'label' => 'Scolta Search',
      'label_display' => '0',
      'provider' => 'scolta',
    ],
    'visibility' => [],
  ])->save();
  echo "Placed Scolta search block in content region.\n";
} else {
  echo "Scolta search block already placed.\n";
}

// ============================================================
// CREATE /search BASIC PAGE
// ============================================================

$existing = \Drupal::entityTypeManager()->getStorage('node')
  ->loadByProperties(['type' => 'page', 'title' => 'Search']);
if (empty($existing)) {
  \Drupal\node\Entity\Node::create([
    'type' => 'page',
    'title' => 'Search',
    'body' => ['value' => '', 'format' => 'plain_text'],
    'status' => 1,
    'path' => ['alias' => '/search'],
  ])->save();
  echo "Created /search page.\n";
} else {
  echo "/search page exists.\n";
}

echo "\n=== Scolta configuration complete ===\n";
