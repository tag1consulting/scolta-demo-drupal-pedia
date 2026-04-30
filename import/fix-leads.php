<?php

/**
 * Fix bad field_lead values by re-extracting from saved body HTML.
 * Run with: ddev drush php:script import/fix-leads.php
 */

function extract_lead(string $html): string {
  // Strip style tags and table/infobox content.
  $stripped = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
  $stripped = preg_replace('/<table[^>]*>.*?<\/table>/si', '', $stripped);
  $stripped = preg_replace('/<div[^>]*class="[^"]*(?:infobox|navbox|hatnote|thumb)[^"]*"[^>]*>.*?<\/div>/si', '', $stripped);

  if (preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $stripped, $m)) {
    foreach ($m[1] as $candidate) {
      $text = trim(strip_tags($candidate));
      if (strlen($text) >= 60) {
        return $text;
      }
    }
  }
  // Fallback to original HTML.
  if (preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $html, $m)) {
    foreach ($m[1] as $candidate) {
      $text = trim(strip_tags($candidate));
      if (strlen($text) >= 60) {
        return $text;
      }
    }
  }
  return '';
}

$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
$db = \Drupal::database();

// Get all featured_article NIDs with bad lead text.
$nids = $db->select('node__field_lead', 'fl')
  ->fields('fl', ['entity_id'])
  ->condition('fl.bundle', 'featured_article')
  ->condition('fl.field_lead_value', '.mw-parser-output%', 'LIKE')
  ->execute()
  ->fetchCol();

$also_bad = $db->select('node__field_lead', 'fl')
  ->fields('fl', ['entity_id'])
  ->condition('fl.bundle', 'featured_article')
  ->condition('fl.field_lead_value', '@me%', 'LIKE')
  ->execute()
  ->fetchCol();

$nids = array_unique(array_merge($nids, $also_bad));
$total = count($nids);
$fixed = 0;
$no_body = 0;

echo "Fixing bad lead text for $total articles...\n";

foreach (array_chunk($nids, 50) as $chunk) {
  $nodes = $nodeStorage->loadMultiple($chunk);
  foreach ($nodes as $node) {
    $body = $node->get('body')->value ?? '';
    if (empty($body)) {
      $no_body++;
      continue;
    }
    $new_lead = extract_lead($body);
    if (empty($new_lead)) {
      continue;
    }
    $node->set('field_lead', ['value' => $new_lead, 'format' => 'plain_text']);
    $node->save();
    $fixed++;
  }
  $nodeStorage->resetCache($chunk);
  echo "  Fixed $fixed / $total (no body: $no_body)\n";
}

echo "\n=== Done. Fixed: $fixed, No body: $no_body ===\n";
