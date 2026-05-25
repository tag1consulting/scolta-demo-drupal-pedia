<?php

/**
 * Smoke test: validates filter_field_descriptions match actual taxonomy terms.
 *
 * Run with: ddev drush php:script tests/validate-filter-descriptions.php
 *
 * Exits with code 1 if any description values don't exist as terms,
 * or any terms aren't mentioned in descriptions.
 */

$config = \Drupal::config('scolta.settings');
$descriptions = $config->get('filter_field_descriptions') ?? [];
$filterFields = $config->get('filter_fields') ?? [];
$exitCode = 0;

$taxonomyMap = [
  'topics' => 'topics',
  'era' => 'era',
  'region' => 'region',
];

foreach ($filterFields as $field) {
  $desc = $descriptions[$field] ?? '';
  if (empty($desc)) {
    echo "WARN: No description for filter field '{$field}'\n";
    continue;
  }

  // Extract enumerated values from description.
  if (!preg_match('/(?:Valid v|V)alues:\s*(.+)/i', $desc, $m)) {
    echo "SKIP: '{$field}' description has no enumerated values\n";
    continue;
  }

  $describedValues = array_map(
    fn($v) => trim($v, " \t\n\r\0\x0B\"'"),
    explode(',', $m[1])
  );
  $describedValues = array_filter($describedValues, fn($v) => $v !== '');

  // Look up actual taxonomy terms.
  $vid = $taxonomyMap[$field] ?? $field;
  $termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $tids = $termStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('vid', $vid)
    ->execute();

  if (empty($tids)) {
    echo "WARN: No terms found in vocabulary '{$vid}' for field '{$field}'\n";
    continue;
  }

  $terms = $termStorage->loadMultiple($tids);
  $actualValues = array_map(fn($t) => $t->label(), $terms);
  sort($actualValues);

  // Check described values exist as terms.
  $missing = array_diff($describedValues, $actualValues);
  if (!empty($missing)) {
    echo "FAIL: '{$field}' description references values not in taxonomy: " . implode(', ', $missing) . "\n";
    $exitCode = 1;
  }

  // Check actual terms are mentioned in description.
  $undocumented = array_diff($actualValues, $describedValues);
  if (!empty($undocumented)) {
    echo "FAIL: '{$field}' taxonomy has terms not in description: " . implode(', ', $undocumented) . "\n";
    $exitCode = 1;
  }

  if (empty($missing) && empty($undocumented)) {
    echo "PASS: '{$field}' — " . count($actualValues) . " terms match description\n";
  }
}

if ($exitCode === 0) {
  echo "\nAll filter descriptions are consistent with taxonomy terms.\n";
} else {
  echo "\nFilter description validation FAILED.\n";
}

exit($exitCode);
