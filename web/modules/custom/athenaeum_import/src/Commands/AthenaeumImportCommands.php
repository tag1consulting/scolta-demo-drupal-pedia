<?php

namespace Drupal\athenaeum_import\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for importing Wikipedia Featured Articles.
 */
class AthenaeumImportCommands extends DrushCommands {

  protected const MEDIAWIKI_API = 'https://en.wikipedia.org/w/api.php';
  protected const USER_AGENT = 'TheAthenaeum/1.0 (https://github.com/tag1consulting/scolta-demos; scolta@tag1consulting.com)';
  protected const RATE_LIMIT_DELAY_MS = 350;
  protected const MANIFEST_PATH = '/var/www/html/import/article-manifest.json';
  protected const BATCH_SIZE = 50;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileRepositoryInterface $fileRepository,
  ) {
    parent::__construct();
  }

  /**
   * Fetches the list of Wikipedia Featured Articles and saves a manifest.
   */
  #[CLI\Command(name: 'athenaeum:fetch-list', aliases: ['ath-list'])]
  #[CLI\Usage(name: 'drush athenaeum:fetch-list', description: 'Fetch ~6,000 Featured Article titles from Wikipedia')]
  public function fetchList(): void {
    $this->output()->writeln('<info>Fetching Wikipedia Featured Articles list...</info>');

    $titles = [];
    $continue = [];

    do {
      $params = [
        'action' => 'query',
        'list' => 'categorymembers',
        'cmtitle' => 'Category:Featured articles',
        'cmtype' => 'page',
        'cmlimit' => '500',
        'format' => 'json',
      ];
      if (!empty($continue)) {
        $params = array_merge($params, $continue);
      }

      $data = $this->apiRequest($params);
      if (!$data || isset($data['error'])) {
        $this->io()->error('API error: ' . json_encode($data['error'] ?? 'unknown'));
        return;
      }

      foreach ($data['query']['categorymembers'] as $member) {
        $titles[] = ['title' => $member['title'], 'pageid' => $member['pageid']];
      }

      $this->output()->writeln(sprintf('  Fetched %d titles so far...', count($titles)));
      $continue = $data['continue'] ?? [];

    } while (!empty($continue));

    file_put_contents(self::MANIFEST_PATH, json_encode($titles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->output()->writeln(sprintf('<info>Saved manifest: %d articles to %s</info>', count($titles), self::MANIFEST_PATH));
  }

  /**
   * Imports Wikipedia Featured Articles from the manifest.
   */
  #[CLI\Command(name: 'athenaeum:import-articles', aliases: ['ath-import'])]
  #[CLI\Option(name: 'start', description: 'Starting index (for resuming)')]
  #[CLI\Option(name: 'limit', description: 'Maximum articles to import (0 = all)')]
  #[CLI\Usage(name: 'drush athenaeum:import-articles', description: 'Import all Featured Articles')]
  #[CLI\Usage(name: 'drush athenaeum:import-articles --start=500 --limit=100', description: 'Resume from article 500, import 100')]
  public function importArticles(array $options = ['start' => 0, 'limit' => 0]): void {
    if (!file_exists(self::MANIFEST_PATH)) {
      $this->io()->error('Manifest not found. Run drush athenaeum:fetch-list first.');
      return;
    }

    $manifest = json_decode(file_get_contents(self::MANIFEST_PATH), TRUE);
    $total = count($manifest);
    $start = (int) $options['start'];
    $limit = (int) $options['limit'];
    $end = $limit > 0 ? min($start + $limit, $total) : $total;

    $this->output()->writeln(sprintf('<info>Importing articles %d–%d of %d...</info>', $start + 1, $end, $total));

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $imported = 0;
    $skipped = 0;

    for ($i = $start; $i < $end; $i++) {
      $item = $manifest[$i];
      $title = $item['title'];
      $pageid = $item['pageid'];

      // Skip if already imported.
      $existing = $nodeStorage->loadByProperties([
        'type' => 'featured_article',
        'field_wikipedia_pageid' => $pageid,
      ]);
      if (!empty($existing)) {
        $skipped++;
        continue;
      }

      $this->output()->writeln(sprintf('  [%d/%d] %s', $i + 1, $total, $title));

      $articleData = $this->fetchArticle($title, $pageid);
      if (!$articleData) {
        $this->output()->writeln(sprintf('  <comment>  SKIP: failed to fetch %s</comment>', $title));
        continue;
      }

      $this->createArticleNode($articleData);
      $imported++;

      usleep(self::RATE_LIMIT_DELAY_MS * 1000);

      if ($imported % self::BATCH_SIZE === 0) {
        $nodeStorage->resetCache();
        $this->output()->writeln(sprintf('<info>  [checkpoint] %d imported, %d skipped</info>', $imported, $skipped));
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Imported: %d, Skipped (existing): %d</info>', $imported, $skipped));
  }

  /**
   * Maps Wikipedia categories to Drupal taxonomy and assigns era/region.
   */
  #[CLI\Command(name: 'athenaeum:import-categories', aliases: ['ath-cats'])]
  #[CLI\Option(name: 'force', description: 'Re-tag all articles, ignoring existing taxonomy')]
  #[CLI\Usage(name: 'drush athenaeum:import-categories', description: 'Map Wikipedia categories to Drupal taxonomy')]
  #[CLI\Usage(name: 'drush athenaeum:import-categories --force', description: 'Re-run for all articles')]
  public function importCategories(array $options = ['force' => FALSE]): void {
    $force = (bool) $options['force'];
    $this->output()->writeln('<info>Importing categories for all featured articles...</info>');
    if ($force) {
      $this->output()->writeln('<comment>--force: re-tagging all articles.</comment>');
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    // Pre-load all taxonomy term IDs once to avoid per-article DB queries.
    $loadTermIds = function (string $vid, array $names) use ($termStorage): array {
      $ids = [];
      foreach ($names as $name) {
        $terms = $termStorage->loadByProperties(['name' => $name, 'vid' => $vid]);
        if ($term = reset($terms)) {
          $ids[$name] = (int) $term->id();
        }
      }
      return $ids;
    };

    $eraTermIds = $loadTermIds('era', [
      'Ancient (before 500 CE)', 'Medieval (500–1500)', 'Early Modern (1500–1800)',
      'Modern (1800–1945)', 'Contemporary (1945–present)', 'Timeless',
    ]);
    $regionTermIds = $loadTermIds('region', [
      'Africa', 'Americas', 'Asia', 'Europe', 'Oceania',
      'Antarctica', 'Space', 'Global / Multiple Regions', 'Not Geographic',
    ]);
    $topicTermIds = $loadTermIds('topics', [
      'Science', 'Mathematics', 'History', 'Military', 'Biography', 'Arts',
      'Technology', 'Engineering', 'Nature', 'Geography', 'Sports',
      'Religion', 'Philosophy', 'Society', 'Medicine',
    ]);

    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->execute();

    $total = count($nids);
    $processed = 0;
    $skipped = 0;

    // Process in chunks of 50 nodes. For each chunk, collect the nodes that
    // need new categories, batch-fetch their Wikipedia categories in one API
    // call, then save all updated nodes together.
    foreach (array_chunk($nids, self::BATCH_SIZE) as $chunkIdx => $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);

      // Separate nodes that need processing from those to skip.
      // Era and region are already 100% complete; only skip if topics are set.
      $toProcess = [];
      foreach ($nodes as $node) {
        if (!$force && !$node->get('field_topics')->isEmpty()) {
          $skipped++;
        }
        else {
          $toProcess[$node->label()] = $node;
        }
      }

      // Batch-fetch categories for all titles needing processing in one API call.
      if (!empty($toProcess)) {
        $params = [
          'action' => 'query',
          'titles' => implode('|', array_keys($toProcess)),
          'prop' => 'categories',
          'cllimit' => '100',
          'format' => 'json',
        ];
        $data = $this->apiRequest($params);
        if (!$data) {
          usleep(2000 * 1000);
          $data = $this->apiRequest($params);
        }
        $pages = $data['query']['pages'] ?? [];

        foreach ($pages as $page) {
          $pageTitle = $page['title'] ?? '';
          $node = $toProcess[$pageTitle] ?? NULL;
          if (!$node) continue;

          $categories = array_map(fn($c) => str_replace('Category:', '', $c['title']), $page['categories'] ?? []);

          $topicTerms = $this->mapCategoriesToTopics($categories, $topicTermIds);
          $eraTermId = $this->mapCategoriesToEra($categories, $eraTermIds);
          $regionTermId = $this->mapCategoriesToRegion($categories, $regionTermIds);

          if (!empty($topicTerms)) {
            $node->set('field_topics', array_map(fn($id) => ['target_id' => $id], $topicTerms));
          }
          if ($eraTermId) {
            $node->set('field_era', ['target_id' => $eraTermId]);
          }
          if ($regionTermId) {
            $node->set('field_region', ['target_id' => $regionTermId]);
          }
          $node->save();
          $processed++;
        }

        usleep(350 * 1000);
      }

      $nodeStorage->resetCache($chunk);

      $count = ($chunkIdx + 1) * self::BATCH_SIZE;
      if ($count % 500 === 0 || $count >= $total) {
        $this->output()->writeln(sprintf('  [%d/%d] processed: %d (skipped: %d)', min($count, $total), $total, $processed, $skipped));
        flush();
      }
    }

    $this->output()->writeln(sprintf('<info>Category import complete. Processed: %d, skipped: %d.</info>', $processed, $skipped));
  }

  /**
   * Downloads and attaches lead images from Wikimedia Commons.
   */
  #[CLI\Command(name: 'athenaeum:import-images', aliases: ['ath-images'])]
  #[CLI\Usage(name: 'drush athenaeum:import-images', description: 'Download lead images from Wikimedia Commons')]
  public function importImages(): void {
    $this->output()->writeln('<info>Importing lead images from Wikimedia Commons...</info>');

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Only load articles that do not yet have an image attached.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->notExists('field_image')
      ->execute();

    $total = count($nids);
    $attached = 0;

    $this->output()->writeln(sprintf('  %d articles need images.', $total));
    flush();

    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);

      // Batch-fetch thumbnail info for all 50 articles in one API call.
      $titleMap = [];
      foreach ($nodes as $node) {
        $titleMap[$node->label()] = $node;
      }
      $params = [
        'action' => 'query',
        'titles' => implode('|', array_keys($titleMap)),
        'prop' => 'pageimages',
        'pithumbsize' => '1200',
        'piprop' => 'thumbnail|name',
        'format' => 'json',
      ];
      $data = $this->apiRequest($params);
      $pages = $data['query']['pages'] ?? [];

      // Download and attach images for pages that have thumbnails.
      foreach ($pages as $page) {
        $thumbnail = $page['thumbnail'] ?? NULL;
        if (!$thumbnail) continue;

        $pageTitle = $page['title'] ?? '';
        $node = $titleMap[$pageTitle] ?? NULL;
        if (!$node) continue;

        $fileEntity = $this->downloadImage($thumbnail['source'], $pageTitle);
        if (!$fileEntity) continue;

        $node->set('field_image', ['target_id' => $fileEntity->id(), 'alt' => $pageTitle]);
        $node->set('field_image_credit', $page['pageimage'] ?? '');
        $node->save();
        $attached++;
      }

      $nodeStorage->resetCache($chunk);

      if ($attached % 200 === 0 && $attached > 0) {
        $this->output()->writeln(sprintf('  attached: %d / ~%d', $attached, $total));
        flush();
      }

      usleep(self::RATE_LIMIT_DELAY_MS * 1000);
    }

    $this->output()->writeln(sprintf('<info>Done. Attached images to %d articles.</info>', $attached));
  }

  /**
   * Back-fills body HTML for articles imported before the body field was added.
   */
  #[CLI\Command(name: 'athenaeum:update-bodies', aliases: ['ath-bodies'])]
  #[CLI\Usage(name: 'drush athenaeum:update-bodies', description: 'Fetch and save missing article body HTML')]
  public function updateBodies(): void {
    $this->output()->writeln('<info>Back-filling article body HTML...</info>');

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Query only NIDs to avoid loading all nodes into memory at once.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->execute();

    $total = count($nids);
    $count = 0;
    $updated = 0;

    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $count++;

        if (!$node->get('body')->isEmpty()) {
          continue;
        }

        $title = $node->label();
        $pageid = $node->get('field_wikipedia_pageid')->value;
        $articleData = $this->fetchArticle($title, (int) $pageid);
        if (!$articleData) continue;

        $node->set('body', ['value' => $articleData['html'], 'format' => 'full_html']);
        if ($node->get('field_lead')->isEmpty()) {
          $node->set('field_lead', ['value' => $articleData['lead'], 'format' => 'plain_text']);
        }
        $node->save();
        $updated++;

        usleep(self::RATE_LIMIT_DELAY_MS * 1000);
      }
      $nodeStorage->resetCache($chunk);
      $this->output()->writeln(sprintf('  [%d/%d] updated: %d', $count, $total, $updated));
    }

    $this->output()->writeln(sprintf('<info>Done. Updated body for %d articles.</info>', $updated));
  }

  /**
   * Creates entity references from Wikipedia "See also" sections.
   */
  #[CLI\Command(name: 'athenaeum:cross-reference', aliases: ['ath-xref'])]
  #[CLI\Usage(name: 'drush athenaeum:cross-reference', description: 'Build cross-references from "See also" sections')]
  public function crossReference(): void {
    $this->output()->writeln('<info>Building cross-references from "See also" sections...</info>');

    $db = \Drupal::database();
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Build title→NID map from the database without loading full node objects.
    $titleMap = $db->select('node_field_data', 'n')
      ->fields('n', ['nid', 'title'])
      ->condition('n.type', 'featured_article')
      ->execute()
      ->fetchAllKeyed(1, 0);
    $titleMap = array_combine(
      array_map('strtolower', array_keys($titleMap)),
      array_values($titleMap)
    );

    $nids = array_values($titleMap);
    $total = count($nids);
    $count = 0;
    $linked = 0;

    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $count++;
        $body = $node->get('body')->value ?? '';
        if (empty($body)) continue;

        $seeAlso = $this->extractSeeAlso($body);
        if (empty($seeAlso)) continue;

        $refs = [];
        foreach ($seeAlso as $linkedTitle) {
          $nid = $titleMap[strtolower($linkedTitle)] ?? NULL;
          if ($nid && $nid !== $node->id()) {
            $refs[] = ['target_id' => $nid];
          }
        }

        if (!empty($refs)) {
          $node->set('field_related_articles', $refs);
          $node->save();
          $linked++;
        }
      }
      $nodeStorage->resetCache($chunk);

      if ($count % 500 === 0) {
        $this->output()->writeln(sprintf('  [%d/%d] linked: %d', $count, $total, $linked));
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Cross-references added to %d articles.</info>', $linked));
  }

  /**
   * Generates Topic Landing Page nodes for each Level-1 topic term.
   */
  #[CLI\Command(name: 'athenaeum:generate-landing-pages', aliases: ['ath-landing'])]
  #[CLI\Usage(name: 'drush athenaeum:generate-landing-pages', description: 'Create Topic Landing Pages for Level-1 topics')]
  public function generateLandingPages(): void {
    $this->output()->writeln('<info>Generating Topic Landing Pages...</info>');

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $level1 = $termStorage->loadByProperties(['vid' => 'topics']);
    $created = 0;

    foreach ($level1 as $term) {
      $parents = $termStorage->loadParents($term->id());
      if (!empty($parents)) continue;

      $existing = $nodeStorage->loadByProperties(['type' => 'topic_landing_page', 'field_topic' => $term->id()]);
      if (!empty($existing)) continue;

      $name = $term->getName();
      $intro = "Explore Wikipedia's Featured Articles in the field of {$name}. "
        . "These are the encyclopedia's highest-quality entries, "
        . "selected through rigorous peer review by Wikipedia's editorial community.";

      $nodeStorage->create([
        'type' => 'topic_landing_page',
        'title' => $name,
        'body' => ['value' => $intro, 'format' => 'basic_html'],
        'field_topic' => ['target_id' => $term->id()],
        'status' => 1,
      ])->save();

      $this->output()->writeln("  Created: $name");
      $created++;
    }

    $this->output()->writeln(sprintf('<info>Done. Created %d landing pages.</info>', $created));
  }

  /**
   * AI enrichment: generates "Why This Matters" blurbs via Claude API.
   */
  #[CLI\Command(name: 'athenaeum:enrich', aliases: ['ath-enrich'])]
  #[CLI\Option(name: 'batch-size', description: 'Articles per batch')]
  #[CLI\Usage(name: 'drush athenaeum:enrich', description: 'Generate AI "Why This Matters" blurbs (requires ANTHROPIC_API_KEY)')]
  public function enrich(array $options = ['batch-size' => 100]): void {
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_ANTHROPIC_API_KEY') ?: getenv('SCOLTA_API_KEY');
    if (!$apiKey) {
      $this->io()->error('ANTHROPIC_API_KEY or SCOLTA_API_KEY not set. Required for AI enrichment.');
      return;
    }

    $this->output()->writeln('<info>Running AI enrichment pass...</info>');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Only load articles that don't yet have a "Why This Matters" blurb and have a lead.
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->notExists('field_why_it_matters')
      ->exists('field_lead')
      ->execute();

    $total = count($nids);
    $enriched = 0;

    $this->output()->writeln(sprintf('  %d articles to enrich.', $total));
    flush();

    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $title = $node->label();
        $lead = $node->get('field_lead')->value ?? '';
        if (empty($lead)) continue;

        $blurb = $this->generateWhyItMatters($title, $lead, $apiKey);
        if ($blurb) {
          $node->set('field_why_it_matters', ['value' => $blurb, 'format' => 'plain_text']);
          $node->save();
          $enriched++;

          if ($enriched % 50 === 0) {
            $this->output()->writeln(sprintf('  [%d/%d enriched] %s', $enriched, $total, $title));
            flush();
          }
        }

        usleep(500000);
      }
      $nodeStorage->resetCache($chunk);
    }

    $this->output()->writeln(sprintf('<info>Done. Enriched %d articles.</info>', $enriched));
  }

  // ============================================================
  // PRIVATE HELPERS
  // ============================================================

  protected function apiRequest(array $params): ?array {
    $url = self::MEDIAWIKI_API . '?' . http_build_query($params);
    $ctx = stream_context_create([
      'http' => [
        'header' => 'User-Agent: ' . self::USER_AGENT,
        'timeout' => 30,
      ],
    ]);
    $response = @file_get_contents($url, FALSE, $ctx);
    if ($response === FALSE) return NULL;
    return json_decode($response, TRUE);
  }

  protected function fetchArticle(string $title, int $pageid): ?array {
    $params = [
      'action' => 'parse',
      'page' => $title,
      'prop' => 'text|categories|revid',
      'disablelimitreport' => '1',
      'disableeditsection' => '1',
      'format' => 'json',
    ];
    $data = $this->apiRequest($params);
    if (!$data || isset($data['error'])) return NULL;

    $parse = $data['parse'] ?? [];
    $html = $parse['text']['*'] ?? '';
    $html = $this->cleanWikipediaHtml($html);

    $lead = $this->extractLead($html);
    $wordCount = str_word_count(strip_tags($html));
    $refCount = substr_count($html, '<li id="cite_note-');

    return [
      'title' => $title,
      'pageid' => $pageid,
      'html' => $html,
      'lead' => $lead,
      'word_count' => $wordCount,
      'ref_count' => $refCount,
      'original_url' => 'https://en.wikipedia.org/wiki/' . urlencode(str_replace(' ', '_', $title)),
    ];
  }

  protected function cleanWikipediaHtml(string $html): string {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    // Load as full HTML document so body element is always present.
    $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"/></head><body>' . $html . '</body></html>');
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);

    // Remove elements by XPath query, converting NodeList to array first to
    // avoid live-NodeList mutation issues during removal.
    $toRemove = [];
    foreach ($xpath->query('//style') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"shortdescription")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"mw-empty-elt")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"mw-editsection")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//sup[contains(@class,"reference")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//table[contains(@class,"infobox")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//table[contains(@class,"clade")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"navbox")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"hatnote")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"dablink")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"sister-wikipedia")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"noprint")]') as $el) $toRemove[] = $el;
    foreach ($toRemove as $el) {
      if ($el->parentNode) $el->parentNode->removeChild($el);
    }

    // Rewrite Wikipedia article links to local paths.
    foreach ($xpath->query('//a[starts-with(@href,"/wiki/")]') as $el) {
      $href = $el->getAttribute('href');
      if (preg_match('/^\/wiki\/([^#?]+)/', $href, $m)) {
        $el->setAttribute('href', '/article/' . $m[1]);
        $el->setAttribute('data-wiki-link', $m[1]);
      }
    }

    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body) {
      $out = '';
      foreach ($body->childNodes as $child) {
        $out .= $dom->saveHTML($child);
      }
      return trim($out);
    }
    return trim($html);
  }

  /**
   * Re-cleans stored article body HTML to remove Wikipedia noise.
   */
  #[CLI\Command(name: 'athenaeum:clean-bodies', aliases: ['ath-clean'])]
  #[CLI\Usage(name: 'drush athenaeum:clean-bodies', description: 'Strip style tags, infoboxes, and navboxes from stored article bodies')]
  public function cleanBodies(): void {
    $this->output()->writeln('<info>Re-cleaning article body HTML...</info>');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->condition('body.format', 'full_html')
      ->execute();

    $total = count($nids);
    $count = 0;

    foreach (array_chunk($nids, 25) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $count++;
        $raw = $node->get('body')->value ?? '';
        if (empty($raw)) continue;
        $clean = $this->cleanWikipediaHtml($raw);
        $node->set('body', ['value' => $clean, 'format' => 'full_html']);
        $node->save();
      }
      $nodeStorage->resetCache($chunk);
      if ($count % 250 === 0) {
        $this->output()->writeln(sprintf('  [%d/%d] cleaned', $count, $total));
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Cleaned %d articles.</info>', $count));
  }

  protected function extractLead(string $html): string {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!DOCTYPE html><html><head><meta charset="UTF-8"/></head><body>' . $html . '</body></html>');
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    // Remove all noise elements before looking for the first real paragraph.
    $toRemove = [];
    foreach ($xpath->query('//style') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//table') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//div[contains(@class,"thumb")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"shortdescription")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"hatnote")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"navbox")]') as $el) $toRemove[] = $el;
    foreach ($xpath->query('//*[contains(@class,"noprint")]') as $el) $toRemove[] = $el;
    foreach ($toRemove as $el) {
      if ($el->parentNode) $el->parentNode->removeChild($el);
    }

    foreach ($xpath->query('//p') as $p) {
      $text = trim($p->textContent);
      if (strlen($text) >= 60) {
        return $text;
      }
    }
    return '';
  }

  /**
   * Re-extracts lead paragraphs from already-cleaned body HTML.
   */
  #[CLI\Command(name: 'athenaeum:fix-leads', aliases: ['ath-leads'])]
  #[CLI\Usage(name: 'drush athenaeum:fix-leads', description: 'Re-extract lead paragraphs from cleaned article bodies')]
  public function fixLeads(): void {
    $this->output()->writeln('<info>Re-extracting lead paragraphs...</info>');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->execute();

    $total = count($nids);
    $count = 0;
    $fixed = 0;

    foreach (array_chunk($nids, 50) as $chunk) {
      $nodes = $nodeStorage->loadMultiple($chunk);
      foreach ($nodes as $node) {
        $count++;
        $body = $node->get('body')->value ?? '';
        if (empty($body)) continue;
        $lead = $this->extractLead($body);
        if (empty($lead)) continue;
        $node->set('field_lead', ['value' => $lead, 'format' => 'plain_text']);
        $node->save();
        $fixed++;
      }
      $nodeStorage->resetCache($chunk);
      if ($count % 500 === 0) {
        $this->output()->writeln(sprintf('  [%d/%d] processed, %d leads updated', $count, $total, $fixed));
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Updated leads for %d articles.</info>', $fixed));
  }

  protected function createArticleNode(array $data): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodeStorage->create([
      'type' => 'featured_article',
      'title' => $data['title'],
      'body' => ['value' => $data['html'], 'format' => 'full_html'],
      'field_lead' => ['value' => $data['lead'], 'format' => 'plain_text'],
      'field_word_count' => $data['word_count'],
      'field_reference_count' => $data['ref_count'],
      'field_original_url' => ['uri' => $data['original_url'], 'title' => $data['title']],
      'field_wikipedia_pageid' => $data['pageid'],
      'status' => 1,
    ])->save();
  }

  protected function fetchLeadImage(string $title): ?array {
    $params = [
      'action' => 'query',
      'titles' => $title,
      'prop' => 'pageimages',
      'pithumbsize' => '1200',
      'piprop' => 'thumbnail|name',
      'format' => 'json',
    ];
    $data = $this->apiRequest($params);
    if (!$data) return NULL;

    $pages = $data['query']['pages'] ?? [];
    $page = reset($pages);
    $thumbnail = $page['thumbnail'] ?? NULL;
    if (!$thumbnail) return NULL;

    $imageName = $page['pageimage'] ?? '';
    $license = $this->fetchImageLicense($imageName);

    return ['url' => $thumbnail['source'], 'credit' => $imageName, 'license' => $license];
  }

  protected function fetchImageLicense(string $imageName): string {
    if (!$imageName) return '';
    $params = [
      'action' => 'query',
      'titles' => 'File:' . $imageName,
      'prop' => 'imageinfo',
      'iiprop' => 'extmetadata',
      'format' => 'json',
    ];
    $data = $this->apiRequest($params);
    if (!$data) return '';
    $pages = $data['query']['pages'] ?? [];
    $page = reset($pages);
    $meta = $page['imageinfo'][0]['extmetadata'] ?? [];
    return $meta['LicenseShortName']['value'] ?? $meta['License']['value'] ?? '';
  }

  protected function downloadImage(string $url, string $title): mixed {
    $ctx = stream_context_create(['http' => ['header' => 'User-Agent: ' . self::USER_AGENT, 'timeout' => 30]]);
    $imageData = @file_get_contents($url, FALSE, $ctx);
    if (!$imageData) return NULL;

    $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($title));
    $destination = 'public://article-images/' . substr($safeTitle, 0, 60) . '.' . $ext;

    $dir = 'public://article-images';
    \Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
    return $this->fileRepository->writeData($imageData, $destination, \Drupal\Core\File\FileExists::Replace);
  }

  protected function mapCategoriesToTopics(array $categories, array $topicTermIds = []): array {
    if (empty($topicTermIds)) {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    }

    // Keyword-to-topic map. Keys are matched as whole words against the
    // lower-cased category name after stripping the "Category:" prefix and
    // Wikipedia maintenance-category patterns.
    $keywordMap = [
      'science' => 'Science', 'physics' => 'Science', 'chemistry' => 'Science',
      'biology' => 'Science', 'astronomy' => 'Science', 'geology' => 'Science',
      'mathematics' => 'Mathematics', 'mathematical' => 'Mathematics',
      'history' => 'History', 'historical' => 'History',
      'war' => 'Military', 'battle' => 'Military', 'military' => 'Military',
      'naval' => 'Military', 'warfare' => 'Military', 'army' => 'Military',
      'biography' => 'Biography',
      'painting' => 'Arts', 'sculpture' => 'Arts', 'visual arts' => 'Arts',
      'music' => 'Arts', 'musician' => 'Arts', 'composer' => 'Arts',
      'literature' => 'Arts', 'novelist' => 'Arts', 'poetry' => 'Arts',
      'cinema' => 'Arts', 'films' => 'Arts', 'theatre' => 'Arts',
      'architecture' => 'Arts', 'artwork' => 'Arts', 'artistic' => 'Arts',
      'technology' => 'Technology', 'engineering' => 'Engineering',
      'computing' => 'Technology', 'software' => 'Technology',
      'electronics' => 'Technology', 'telecommunications' => 'Technology',
      'animal' => 'Nature', 'species' => 'Nature', 'plant' => 'Nature',
      'ecology' => 'Nature', 'wildlife' => 'Nature', 'insect' => 'Nature',
      'bird' => 'Nature', 'fish' => 'Nature', 'mammal' => 'Nature',
      'geography' => 'Geography', 'country' => 'Geography', 'cities' => 'Geography',
      'island' => 'Geography', 'river' => 'Geography', 'mountain' => 'Geography',
      'sports' => 'Sports', 'sport' => 'Sports', 'olympic' => 'Sports',
      'football' => 'Sports', 'cricket' => 'Sports', 'tennis' => 'Sports',
      'religion' => 'Religion', 'christianity' => 'Religion', 'islam' => 'Religion',
      'buddhism' => 'Religion', 'hinduism' => 'Religion', 'judaism' => 'Religion',
      'philosophy' => 'Philosophy', 'philosopher' => 'Philosophy',
      'society' => 'Society', 'politics' => 'Society', 'economics' => 'Society',
      'law' => 'Society', 'government' => 'Society',
      'medicine' => 'Medicine', 'disease' => 'Medicine', 'health' => 'Medicine',
      'surgery' => 'Medicine', 'anatomy' => 'Medicine', 'pharmacology' => 'Medicine',
    ];

    // Skip Wikipedia maintenance categories that contain these patterns.
    $skipPatterns = [
      'articles with', 'wikipedia articles', 'articles needing', 'pages using',
      'cs1 ', 'webarchive', 'all articles', 'good articles', 'featured articles',
      'living people', 'died ', 'births', 'deaths', 'orphaned articles',
      'short description', 'use dmy', 'use mdy', 'infobox',
    ];

    $matchedTopics = [];
    foreach ($categories as $cat) {
      $catLower = strtolower($cat);

      // Skip maintenance categories.
      $skip = FALSE;
      foreach ($skipPatterns as $pattern) {
        if (str_contains($catLower, $pattern)) {
          $skip = TRUE;
          break;
        }
      }
      if ($skip) continue;

      foreach ($keywordMap as $keyword => $topicName) {
        // Use word-boundary matching: keyword must appear as a complete word.
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/', $catLower)) {
          $matchedTopics[$topicName] = TRUE;
        }
      }
    }

    $termIds = [];
    foreach (array_keys($matchedTopics) as $topicName) {
      if (!empty($topicTermIds)) {
        if (isset($topicTermIds[$topicName])) {
          $termIds[] = $topicTermIds[$topicName];
        }
      }
      else {
        $terms = $termStorage->loadByProperties(['name' => $topicName, 'vid' => 'topics']);
        if ($term = reset($terms)) {
          $termIds[] = $term->id();
        }
      }
    }
    return array_unique($termIds);
  }

  protected function mapCategoriesToEra(array $categories, array $eraTermIds = []): ?int {
    if (empty($eraTermIds)) {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    }
    $eraMap = [
      'Ancient (before 500 CE)' => ['ancient', 'classical antiquity', 'roman empire', 'bc ', 'bce', 'greek antiquity', 'bronze age', 'iron age', 'egypt', 'mesopotamia'],
      'Medieval (500–1500)' => ['medieval', 'middle ages', 'byzantine', 'crusades', 'feudal', 'viking', '10th century', '11th century', '12th century', '13th century', '14th century', '15th century'],
      'Early Modern (1500–1800)' => ['early modern', '16th century', '17th century', '18th century', 'renaissance', 'reformation', 'enlightenment', 'colonial era'],
      'Modern (1800–1945)' => ['19th century', 'world war', 'victorian', 'industrial revolution', 'edwardian', 'interwar'],
      'Contemporary (1945–present)' => ['21st century', 'cold war', 'contemporary', 'post-war', '1950s', '1960s', '1970s', '1980s', '1990s', '2000s'],
    ];

    $catsStr = strtolower(implode(' ', $categories));
    foreach ($eraMap as $eraName => $keywords) {
      foreach ($keywords as $kw) {
        if (str_contains($catsStr, $kw)) {
          if (!empty($eraTermIds)) {
            return $eraTermIds[$eraName] ?? NULL;
          }
          $terms = $termStorage->loadByProperties(['name' => $eraName, 'vid' => 'era']);
          if ($term = reset($terms)) return $term->id();
        }
      }
    }
    if (!empty($eraTermIds)) {
      return $eraTermIds['Timeless'] ?? NULL;
    }
    $terms = $termStorage->loadByProperties(['name' => 'Timeless', 'vid' => 'era']);
    if ($term = reset($terms)) return $term->id();
    return NULL;
  }

  protected function mapCategoriesToRegion(array $categories, array $regionTermIds = []): ?int {
    if (empty($regionTermIds)) {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    }
    $regionMap = [
      'Africa' => ['africa', 'african', 'egypt', 'ethiopia', 'nigeria', 'kenya', 'zimbabwe', 'ghana', 'sudan'],
      'Americas' => ['america', 'united states', 'canada', 'mexico', 'brazil', 'latin america', 'caribbean', 'argentina', 'chile'],
      'Asia' => ['asia', 'asian', 'china', 'japan', 'india', 'korea', 'middle east', 'iran', 'iraq', 'turkey', 'thailand', 'vietnam'],
      'Europe' => ['europe', 'european', 'british', 'french', 'german', 'italian', 'spanish', 'roman', 'greek', 'russian', 'dutch'],
      'Oceania' => ['australia', 'new zealand', 'pacific islands', 'papua', 'oceania'],
      'Antarctica' => ['antarctic', 'antarctica', 'south pole'],
      'Space' => ['space', 'astronomy', 'planet', 'star ', 'galaxy', 'universe', 'cosmos', 'spacecraft'],
      'Global / Multiple Regions' => ['world', 'global', 'international', 'worldwide', 'transatlantic'],
    ];

    $catsStr = strtolower(implode(' ', $categories));
    foreach ($regionMap as $regionName => $keywords) {
      foreach ($keywords as $kw) {
        if (str_contains($catsStr, $kw)) {
          if (!empty($regionTermIds)) {
            return $regionTermIds[$regionName] ?? NULL;
          }
          $terms = $termStorage->loadByProperties(['name' => $regionName, 'vid' => 'region']);
          if ($term = reset($terms)) return $term->id();
        }
      }
    }
    if (!empty($regionTermIds)) {
      return $regionTermIds['Not Geographic'] ?? NULL;
    }
    $terms = $termStorage->loadByProperties(['name' => 'Not Geographic', 'vid' => 'region']);
    if ($term = reset($terms)) return $term->id();
    return NULL;
  }

  protected function extractSeeAlso(string $html): array {
    $titles = [];
    // Match the "See also" section between its h2 and the next h2 (or end of string).
    if (preg_match('/<h2[^>]*>\s*(?:<span[^>]*>)?See also(?:<\/span>)?\s*<\/h2>(.*?)(?=<h2|$)/si', $html, $section)) {
      // Body HTML uses data-wiki-link attributes added during import to track article references.
      preg_match_all('/data-wiki-link="([^"]+)"/i', $section[1], $matches);
      foreach ($matches[1] as $link) {
        $titles[] = str_replace('_', ' ', urldecode($link));
      }
    }
    return $titles;
  }

  protected function generateWhyItMatters(string $title, string $lead, string $apiKey): ?string {
    $prompt = "In 2-3 sentences, explain why the Wikipedia article about '{$title}' matters. "
      . "The article begins: \"{$lead}\". Write for a curious general audience. Don't start with 'This article'.";

    $payload = json_encode([
      'model' => 'claude-haiku-4-5-20251001',
      'max_tokens' => 200,
      'messages' => [['role' => 'user', 'content' => $prompt]],
    ]);

    $ctx = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
          'Content-Type: application/json',
          'x-api-key: ' . $apiKey,
          'anthropic-version: 2023-06-01',
        ]),
        'content' => $payload,
        'timeout' => 30,
      ],
    ]);

    $response = @file_get_contents('https://api.anthropic.com/v1/messages', FALSE, $ctx);
    if (!$response) return NULL;
    $data = json_decode($response, TRUE);
    return $data['content'][0]['text'] ?? NULL;
  }

}
