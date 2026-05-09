<?php
/**
 * Creates the "About This Demo" page for The Athenaeum.
 * Run with: ddev drush php:script scripts/setup-about-page.php
 * Idempotent: skips creation if the page already exists.
 */

use Drupal\node\Entity\Node;

$existing = \Drupal::entityTypeManager()
  ->getStorage('node')
  ->loadByProperties(['type' => 'page', 'title' => 'About This Demo']);

if ($existing) {
  echo "About This Demo page already exists — skipping.\n";
  return;
}

$body = <<<'HTML'
<h2>About This Site</h2>
<p><strong>The Athenaeum is a fictional encyclopedia.</strong> It was created by Tag1 Consulting to demonstrate the capabilities of Scolta, an open-source AI-powered search platform, on a very large Drupal 11 content corpus.</p>

<h2>What You Are Looking At</h2>
<p>This site is a Drupal 11 demonstration built to show how Scolta performs at scale. The site contains over 12,000 articles spanning topics across history, science, technology, geography, arts, and culture. The database behind this site is approximately 586 MB — orders of magnitude larger than other Scolta demo sites — making it a rigorous test of Scolta's performance on a real-world-scale content corpus.</p>

<h2>What Scolta Does Here</h2>
<p>The search bar uses Scolta to let you explore the encyclopedia by asking natural language questions. Try these example queries:</p>
<ul>
  <li>"What caused the fall of the Roman Empire?"</li>
  <li>"How does photosynthesis work?"</li>
  <li>"Who was Ada Lovelace and what did she contribute to computing?"</li>
  <li>"What is quantum entanglement?"</li>
  <li>"How are black holes formed?"</li>
</ul>
<p>Scolta uses Claude via the Anthropic API for query expansion and AI-generated overviews, and a custom BM25-based scoring layer over the full article corpus.</p>

<h2>About Tag1 Consulting</h2>
<p>Tag1 Consulting is one of the leading Drupal development and consulting firms in the world. Tag1 built and open-sources Scolta as a demonstration of what AI-augmented content discovery can look like on modern Drupal sites. For more information about Tag1 and Scolta, visit <a href="https://tag1.com">tag1.com</a>.</p>

<h2>Reuse and Attribution</h2>
<p>If you are evaluating Scolta for your organization and have questions about how this demo was built or how to implement Scolta for your use case, contact Tag1 Consulting.</p>
HTML;

$node = Node::create([
  'type'     => 'page',
  'title'    => 'About This Demo',
  'langcode' => 'en',
  'status'   => 1,
  'uid'      => 1,
  'body'     => ['value' => $body, 'format' => 'full_html'],
  'path'     => [['alias' => '/about/demo']],
]);
$node->save();

echo "Created 'About This Demo' at /about/demo (node/" . $node->id() . ")\n";
