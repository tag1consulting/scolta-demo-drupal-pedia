<?php

/**
 * Setup: About page, display modes, image styles, pathauto bulk update.
 * Run with: ddev drush php:script import/setup-pages-and-display.php
 */

use Drupal\node\Entity\Node;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

// ============================================================
// ABOUT PAGE
// ============================================================

$existing = \Drupal::entityTypeManager()->getStorage('node')
  ->loadByProperties(['type' => 'page', 'title' => 'About The Athenaeum']);

if (empty($existing)) {
  Node::create([
    'type' => 'page',
    'title' => 'About The Athenaeum',
    'body' => [
      'value' => <<<HTML
<h2>What Is The Athenaeum?</h2>
<p>The Athenaeum is an encyclopedia of Wikipedia's <a href="https://en.wikipedia.org/wiki/Wikipedia:Featured_articles">Featured Articles</a> — the approximately 6,900 entries that have passed Wikipedia's most rigorous editorial review. These articles represent the best of the best: comprehensive, well-sourced, neutrally written, and peer-reviewed by the Wikipedia community.</p>

<p>Topics span every domain of human knowledge: from ancient civilizations to particle physics, from endangered species to architectural landmarks, from obscure medieval battles to contemporary music. If Wikipedia's millions of volunteers judged an article worthy of the gold star, it's here.</p>

<h2>Why Scolta?</h2>
<p>This site is a demonstration of <strong>Scolta</strong>, a search quality enhancement system developed by <a href="https://tag1consulting.com">Tag1 Consulting</a>. Scolta uses AI-powered semantic understanding to surface articles that are conceptually related — even when they share no keywords.</p>

<p>Try searching for <em>"ancient water systems"</em>. A keyword search returns articles containing those exact words. Scolta returns Roman aqueducts, Mesopotamian irrigation canals, Hawaiian fishponds, Persian qanats, and Dutch polders — because it understands what you're asking, not just what you typed.</p>

<p>The breadth and quality of Wikipedia's Featured Articles makes this the ideal stress test: 6,900 articles across every conceivable topic, with Scolta finding the unexpected connections between them.</p>

<h2>Showcase Queries</h2>
<p>These queries demonstrate Scolta's cross-domain semantic discovery:</p>
<ul>
  <li><strong>ancient water systems</strong> → Roman aqueducts, Mesopotamian irrigation, Hawaiian fishponds, qanats</li>
  <li><strong>survival in extreme conditions</strong> → Antarctic exploration, extremophile bacteria, Apollo 13, Andes flight disaster</li>
  <li><strong>beautiful mathematics</strong> → Fractals, golden ratio, Euler's identity, Islamic geometric patterns</li>
  <li><strong>animals that build</strong> → Beavers, termite mounds, bowerbirds, coral reefs</li>
  <li><strong>forgotten kingdoms</strong> → Khmer Empire, Aksum, Majapahit, Great Zimbabwe, Nabataeans</li>
  <li><strong>when science was wrong</strong> → Phlogiston, luminiferous aether, miasma theory, Piltdown Man</li>
</ul>

<h2>Content License</h2>
<p>All encyclopedia content is adapted from Wikipedia's Featured Articles, used under the <a href="https://creativecommons.org/licenses/by-sa/4.0/">Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)</a> license. Every article links back to its original Wikipedia source. Images are sourced from Wikimedia Commons with individual licenses noted per article.</p>

<p>The Athenaeum's code is released under GPL-2.0-or-later. Scolta is a proprietary product of Tag1 Consulting.</p>

<h2>About Tag1 Consulting</h2>
<p><a href="https://tag1consulting.com">Tag1 Consulting</a> is a Drupal and open-source consultancy. We built Scolta to solve a real problem: search quality on content-rich sites, where users know what they want but can't always articulate the exact keywords. Scolta bridges that gap with semantic understanding, query expansion, and AI-powered re-ranking.</p>
HTML,
      'format' => 'full_html',
    ],
    'status' => 1,
    'path' => ['alias' => '/about'],
  ])->save();
  echo "Created About page.\n";
} else {
  echo "About page exists.\n";
}

// ============================================================
// SOURCES PAGE
// ============================================================

$existing = \Drupal::entityTypeManager()->getStorage('node')
  ->loadByProperties(['type' => 'page', 'title' => 'Sources & Attribution']);

if (empty($existing)) {
  Node::create([
    'type' => 'page',
    'title' => 'Sources & Attribution',
    'body' => [
      'value' => <<<HTML
<h2>Content Attribution</h2>
<p>All encyclopedia articles are adapted from <a href="https://en.wikipedia.org/wiki/Wikipedia:Featured_articles">Wikipedia's Featured Articles</a>, used under the <a href="https://creativecommons.org/licenses/by-sa/4.0/">Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0)</a> license.</p>

<p>Each article on this site links to its original Wikipedia source. Content may have been reformatted for display; the substance has not been altered.</p>

<h2>Image Attribution</h2>
<p>Lead images are sourced from <a href="https://commons.wikimedia.org">Wikimedia Commons</a>. Each image's credit and license is noted on the article page where it appears. Common licenses include Public Domain, CC0, CC BY 4.0, and CC BY-SA 4.0.</p>

<h2>Data Collection</h2>
<p>Content was fetched from the <a href="https://www.mediawiki.org/wiki/API:Main_page">MediaWiki API</a> following Wikipedia's API etiquette guidelines, with rate limiting and a proper User-Agent string identifying this application.</p>

<h2>Software</h2>
<ul>
  <li><strong>Drupal 11</strong> — GPL-2.0-or-later — <a href="https://drupal.org">drupal.org</a></li>
  <li><strong>Scolta</strong> — Proprietary — <a href="https://tag1consulting.com">Tag1 Consulting</a></li>
  <li><strong>Leaflet.js</strong> — BSD 2-Clause — <a href="https://leafletjs.com">leafletjs.com</a></li>
</ul>
HTML,
      'format' => 'full_html',
    ],
    'status' => 1,
    'path' => ['alias' => '/sources'],
  ])->save();
  echo "Created Sources page.\n";
} else {
  echo "Sources page exists.\n";
}

// ============================================================
// CONFIGURE ENTITY VIEW DISPLAY for featured_article
// Set field weights so the display is coherent
// ============================================================

$display = EntityViewDisplay::load('node.featured_article.default');
if (!$display) {
  $display = EntityViewDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'featured_article',
    'mode' => 'default',
    'status' => TRUE,
  ]);
}

$display->setComponent('body', ['weight' => 10, 'label' => 'hidden', 'type' => 'text_default']);
$display->setComponent('field_lead', ['weight' => 5, 'label' => 'hidden', 'type' => 'text_default']);
$display->setComponent('field_image', ['weight' => 3, 'label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'large', 'image_link' => '']]);
$display->setComponent('field_topics', ['weight' => 15, 'label' => 'inline', 'type' => 'entity_reference_label', 'settings' => ['link' => TRUE]]);
$display->setComponent('field_era', ['weight' => 16, 'label' => 'inline', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$display->setComponent('field_region', ['weight' => 17, 'label' => 'inline', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$display->setComponent('field_featured_date', ['weight' => 18, 'label' => 'inline', 'type' => 'datetime_default', 'settings' => ['format_type' => 'medium', 'timezone_override' => '']]);
$display->setComponent('field_original_url', ['weight' => 20, 'label' => 'inline', 'type' => 'link', 'settings' => ['trim_length' => 80, 'url_only' => FALSE, 'url_plain' => FALSE, 'rel' => '', 'target' => '_blank']]);
$display->setComponent('field_related_articles', ['weight' => 25, 'label' => 'above', 'type' => 'entity_reference_label', 'settings' => ['link' => TRUE]]);

// Hide fields that are display via theme, not field formatters.
$display->removeComponent('field_word_count');
$display->removeComponent('field_reference_count');
$display->removeComponent('field_image_credit');
$display->removeComponent('field_wikipedia_pageid');
$display->removeComponent('field_infobox_data');
$display->removeComponent('field_why_it_matters');
$display->removeComponent('field_coordinates');

$display->save();
echo "Configured featured_article default view display.\n";

// Teaser display
$teaser = EntityViewDisplay::load('node.featured_article.teaser');
if (!$teaser) {
  $teaser = EntityViewDisplay::create([
    'targetEntityType' => 'node',
    'bundle' => 'featured_article',
    'mode' => 'teaser',
    'status' => TRUE,
  ]);
}
$teaser->setComponent('field_image', ['weight' => 1, 'label' => 'hidden', 'type' => 'image', 'settings' => ['image_style' => 'medium', 'image_link' => 'content']]);
$teaser->setComponent('field_lead', ['weight' => 5, 'label' => 'hidden', 'type' => 'text_default']);
$teaser->setComponent('field_topics', ['weight' => 10, 'label' => 'hidden', 'type' => 'entity_reference_label', 'settings' => ['link' => FALSE]]);
$teaser->removeComponent('body');
$teaser->removeComponent('field_era');
$teaser->removeComponent('field_region');
$teaser->removeComponent('field_featured_date');
$teaser->removeComponent('field_original_url');
$teaser->removeComponent('field_related_articles');
$teaser->removeComponent('field_word_count');
$teaser->removeComponent('field_reference_count');
$teaser->removeComponent('field_image_credit');
$teaser->removeComponent('field_wikipedia_pageid');
$teaser->removeComponent('field_infobox_data');
$teaser->removeComponent('field_why_it_matters');
$teaser->removeComponent('field_coordinates');
$teaser->save();
echo "Configured featured_article teaser view display.\n";

echo "\n=== Pages and display setup complete ===\n";
