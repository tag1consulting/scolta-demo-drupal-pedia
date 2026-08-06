<?php
// Sync the scolta front-end assets into the installed Drupal module.
//
// The module itself comes from Composer (drupal/scolta), but its bundled JS and
// CSS can lag the monorepo. Copy both from the monorepo source when present,
// falling back to the installed scolta-php vendor copy otherwise. JS and CSS
// MUST be synced together: the JS reserves a fixed-height, "Show more" AI
// summary slot via the .scolta-ai-summary--reserved class, and the matching
// height/overflow rules live in the CSS. Syncing one without the other leaves
// the reserved slot unclipped, so the loading skeleton takes over the page.
$monorepoBase = __DIR__ . '/../../../packages/scolta-php/assets';
$vendorBase   = __DIR__ . '/../vendor/tag1/scolta-php/assets';

// Each asset: source path relative to the assets base, and the module subdir.
$assets = [
    ['js/scolta.js',   'js'],
    ['css/scolta.css', 'css'],
];

$status = 0;
foreach ($assets as [$rel, $subdir]) {
    $monorepo = "$monorepoBase/$rel";
    $vendor   = "$vendorBase/$rel";
    $src = file_exists($monorepo) ? $monorepo : (file_exists($vendor) ? $vendor : null);

    if ($src === null) {
        fwrite(STDERR, "sync-scolta-assets: $rel not found\n");
        $status = 1;
        continue;
    }

    $file = basename($rel);
    foreach (glob(__DIR__ . '/../web/modules/contrib/scolta*/' . $subdir) as $dir) {
        copy($src, $dir . '/' . $file);
    }
}

exit($status);
