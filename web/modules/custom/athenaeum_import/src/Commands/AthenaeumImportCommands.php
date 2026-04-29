<?php

namespace Drupal\athenaeum_import\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileRepositoryInterface;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

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
    protected LoggerInterface $logger,
  ) {
    parent::__construct();
  }

  /**
   * Fetches the list of Wikipedia Featured Articles and saves a manifest.
   *
   * @command athenaeum:fetch-list
   * @aliases ath-list
   * @usage drush athenaeum:fetch-list
   */
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
        $this->logger->error('API error: @err', ['@err' => json_encode($data['error'] ?? 'unknown')]);
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
   *
   * @command athenaeum:import-articles
   * @aliases ath-import
   * @option start Starting index (for resuming)
   * @option limit Maximum articles to import (0 = all)
   * @usage drush athenaeum:import-articles
   * @usage drush athenaeum:import-articles --start=500 --limit=100
   */
  public function importArticles(array $options = ['start' => 0, 'limit' => 0]): void {
    if (!file_exists(self::MANIFEST_PATH)) {
      $this->logger->error('Manifest not found. Run drush athenaeum:fetch-list first.');
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

      // Check if already imported.
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
        $this->logger->warning('Failed to fetch article: @title', ['@title' => $title]);
        continue;
      }

      $this->createArticleNode($articleData);
      $imported++;

      // Rate limiting.
      usleep(self::RATE_LIMIT_DELAY_MS * 1000);

      // Batch flush entity cache.
      if ($imported % self::BATCH_SIZE === 0) {
        $this->entityTypeManager->getStorage('node')->resetCache();
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Imported: %d, Skipped (existing): %d</info>', $imported, $skipped));
  }

  /**
   * Maps Wikipedia categories to Drupal taxonomy and assigns era/region.
   *
   * @command athenaeum:import-categories
   * @aliases ath-cats
   * @usage drush athenaeum:import-categories
   */
  public function importCategories(): void {
    $this->output()->writeln('<info>Importing categories for all featured articles...</info>');

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadByProperties(['type' => 'featured_article']);
    $total = count($nodes);
    $count = 0;

    foreach ($nodes as $node) {
      $count++;
      $title = $node->label();
      $this->output()->writeln(sprintf('  [%d/%d] %s', $count, $total, $title));

      $params = [
        'action' => 'query',
        'titles' => $title,
        'prop' => 'categories',
        'cllimit' => '100',
        'format' => 'json',
      ];
      $data = $this->apiRequest($params);
      if (!$data) continue;

      $pages = $data['query']['pages'] ?? [];
      $page = reset($pages);
      $categories = array_map(fn($c) => str_replace('Category:', '', $c['title']), $page['categories'] ?? []);

      $topicTerms = $this->mapCategoriesToTopics($categories);
      $eraTermId = $this->mapCategoriesToEra($categories);
      $regionTermId = $this->mapCategoriesToRegion($categories);

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

      usleep(self::RATE_LIMIT_DELAY_MS * 1000);
    }

    $this->output()->writeln('<info>Category import complete.</info>');
  }

  /**
   * Downloads and attaches lead images from Wikimedia Commons.
   *
   * @command athenaeum:import-images
   * @aliases ath-images
   * @usage drush athenaeum:import-images
   */
  public function importImages(): void {
    $this->output()->writeln('<info>Importing lead images from Wikimedia Commons...</info>');

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadByProperties(['type' => 'featured_article']);
    $total = count($nodes);
    $count = 0;
    $attached = 0;

    foreach ($nodes as $node) {
      $count++;

      // Skip if image already attached.
      if (!$node->get('field_image')->isEmpty()) {
        continue;
      }

      $title = $node->label();
      $this->output()->writeln(sprintf('  [%d/%d] %s', $count, $total, $title));

      $imageData = $this->fetchLeadImage($title);
      if (!$imageData) {
        continue;
      }

      $fileEntity = $this->downloadImage($imageData['url'], $title);
      if (!$fileEntity) {
        continue;
      }

      $node->set('field_image', [
        'target_id' => $fileEntity->id(),
        'alt' => $title,
      ]);
      $credit = $imageData['credit'] ?? '';
      if ($imageData['license'] ?? '') {
        $credit .= ($credit ? ' · ' : '') . $imageData['license'];
      }
      $node->set('field_image_credit', $credit);
      $node->save();
      $attached++;

      usleep(self::RATE_LIMIT_DELAY_MS * 1000);
    }

    $this->output()->writeln(sprintf('<info>Done. Attached images to %d articles.</info>', $attached));
  }

  /**
   * Creates entity references from Wikipedia "See also" sections.
   *
   * @command athenaeum:cross-reference
   * @aliases ath-xref
   * @usage drush athenaeum:cross-reference
   */
  public function crossReference(): void {
    $this->output()->writeln('<info>Building cross-references from "See also" sections...</info>');

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadByProperties(['type' => 'featured_article']);
    $total = count($nodes);
    $count = 0;
    $linked = 0;

    // Build a title → nid lookup map.
    $titleMap = [];
    foreach ($nodes as $node) {
      $titleMap[strtolower($node->label())] = $node->id();
    }

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
        $this->output()->writeln(sprintf('  [%d/%d] %s → %d links', $count, $total, $node->label(), count($refs)));
      }
    }

    $this->output()->writeln(sprintf('<info>Done. Added cross-references to %d articles.</info>', $linked));
  }

  /**
   * Generates Topic Landing Page nodes for each Level-1 topic term.
   *
   * @command athenaeum:generate-landing-pages
   * @aliases ath-landing
   * @usage drush athenaeum:generate-landing-pages
   */
  public function generateLandingPages(): void {
    $this->output()->writeln('<info>Generating Topic Landing Pages...</info>');

    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Get Level-1 terms (no parent).
    $level1 = $termStorage->loadByProperties(['vid' => 'topics']);
    $created = 0;

    foreach ($level1 as $term) {
      // Only top-level (no parent).
      $parents = $termStorage->loadParents($term->id());
      if (!empty($parents)) continue;

      // Check if landing page exists.
      $existing = $nodeStorage->loadByProperties([
        'type' => 'topic_landing_page',
        'field_topic' => $term->id(),
      ]);
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

      $this->output()->writeln("  Created landing page: $name");
      $created++;
    }

    $this->output()->writeln(sprintf('<info>Done. Created %d landing pages.</info>', $created));
  }

  /**
   * AI enrichment pass: generates "Why This Matters" blurbs.
   *
   * @command athenaeum:enrich
   * @aliases ath-enrich
   * @option batch-size Number of articles to process per batch
   * @usage drush athenaeum:enrich
   */
  public function enrich(array $options = ['batch-size' => 100]): void {
    $apiKey = getenv('ANTHROPIC_API_KEY') ?: getenv('SCOLTA_ANTHROPIC_API_KEY');
    if (!$apiKey) {
      $this->logger->error('ANTHROPIC_API_KEY not set. This command requires it for AI enrichment.');
      return;
    }

    $this->output()->writeln('<info>Running AI enrichment pass...</info>');
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodes = $nodeStorage->loadByProperties(['type' => 'featured_article']);
    $total = count($nodes);
    $count = 0;
    $enriched = 0;

    foreach ($nodes as $node) {
      $count++;

      if (!$node->get('field_why_it_matters')->isEmpty()) continue;

      $title = $node->label();
      $lead = $node->get('field_lead')->value ?? '';
      if (empty($lead)) continue;

      $this->output()->writeln(sprintf('  [%d/%d] %s', $count, $total, $title));

      $blurb = $this->generateWhyItMatters($title, $lead, $apiKey);
      if ($blurb) {
        $node->set('field_why_it_matters', ['value' => $blurb, 'format' => 'plain_text']);
        $node->save();
        $enriched++;
      }

      usleep(500000); // 500ms between AI calls.
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
      'prop' => 'text|sections|categories|links|images|iwlinks|properties|revid',
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
    // Remove edit section links.
    $html = preg_replace('/<span class="mw-editsection[^"]*"[^>]*>.*?<\/span>/s', '', $html);
    // Remove Wikipedia navboxes (hatnotes, infobox sidebars become divs).
    $html = preg_replace('/<div class="(?:navbox|mw-empty-elt|sister-wikipedia|noprint|dablink|hatnote)[^"]*"[^>]*>.*?<\/div>/si', '', $html);
    // Remove reference section clutter (keep ref list).
    $html = preg_replace('/<sup[^>]*class="[^"]*reference[^"]*"[^>]*>.*?<\/sup>/s', '', $html);
    // Fix internal Wikipedia links → strip to plain text for now (cross-ref pass will fix).
    $html = preg_replace('/<a href="\/wiki\/([^"#]+)"[^>]*>([^<]+)<\/a>/', '<a href="/article/$1" data-wiki-link="$1">$2</a>', $html);
    // Remove external link icons.
    $html = preg_replace('/<span class="mw-[a-z-]+-icon[^"]*"[^>]*>.*?<\/span>/s', '', $html);
    return trim($html);
  }

  protected function extractLead(string $html): string {
    // The lead is the first <p> that's substantial.
    if (preg_match('/<p[^>]*>(.+?)<\/p>/s', $html, $m)) {
      return trim(strip_tags($m[1]));
    }
    return '';
  }

  protected function createArticleNode(array $data): void {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $node = $nodeStorage->create([
      'type' => 'featured_article',
      'title' => $data['title'],
      'body' => [
        'value' => $data['html'],
        'format' => 'full_html',
      ],
      'field_lead' => ['value' => $data['lead'], 'format' => 'plain_text'],
      'field_word_count' => $data['word_count'],
      'field_reference_count' => $data['ref_count'],
      'field_original_url' => ['uri' => $data['original_url'], 'title' => $data['title']],
      'field_wikipedia_pageid' => $data['pageid'],
      'status' => 1,
    ]);
    $node->save();
  }

  protected function fetchLeadImage(string $title): ?array {
    $params = [
      'action' => 'query',
      'titles' => $title,
      'prop' => 'pageimages|imageinfo',
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

    return [
      'url' => $thumbnail['source'],
      'credit' => $imageName,
      'license' => $license,
    ];
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

    \Drupal::service('file_system')->prepareDirectory('public://article-images', \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);

    return $this->fileRepository->writeData($imageData, $destination, \Drupal\Core\File\FileExists::Replace);
  }

  protected function mapCategoriesToTopics(array $categories): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    $keywordMap = [
      'science' => 'Science', 'physics' => 'Science', 'chemistry' => 'Science',
      'biology' => 'Science', 'astronomy' => 'Science', 'mathematics' => 'Mathematics',
      'history' => 'History', 'war' => 'Military', 'battle' => 'Military',
      'biography' => 'Biography', 'people' => 'Biography',
      'art' => 'Arts', 'music' => 'Arts', 'literature' => 'Arts', 'film' => 'Arts',
      'architecture' => 'Arts',
      'technology' => 'Technology', 'engineering' => 'Engineering',
      'computing' => 'Technology', 'software' => 'Technology',
      'nature' => 'Nature', 'animal' => 'Nature', 'species' => 'Nature', 'plant' => 'Nature',
      'geography' => 'Geography', 'country' => 'Geography', 'city' => 'Geography',
      'sports' => 'Sports', 'sport' => 'Sports',
      'religion' => 'Religion', 'philosophy' => 'Philosophy',
      'society' => 'Society', 'politics' => 'Society', 'economics' => 'Society',
      'medicine' => 'Medicine', 'disease' => 'Medicine', 'health' => 'Medicine',
    ];

    $matchedTopics = [];
    foreach ($categories as $cat) {
      $catLower = strtolower($cat);
      foreach ($keywordMap as $keyword => $topicName) {
        if (str_contains($catLower, $keyword)) {
          $matchedTopics[$topicName] = TRUE;
        }
      }
    }

    $termIds = [];
    foreach (array_keys($matchedTopics) as $topicName) {
      $terms = $termStorage->loadByProperties(['name' => $topicName, 'vid' => 'topics']);
      if ($term = reset($terms)) {
        $termIds[] = $term->id();
      }
    }

    return array_unique($termIds);
  }

  protected function mapCategoriesToEra(array $categories): ?int {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    $eraMap = [
      'Ancient (before 500 CE)' => ['ancient', 'classical antiquity', 'roman empire', 'greek', 'egypt', 'mesopotamia', 'bronze age', 'iron age'],
      'Medieval (500–1500)' => ['medieval', 'middle ages', 'byzantine', 'crusades', 'feudal', 'viking'],
      'Early Modern (1500–1800)' => ['early modern', '16th century', '17th century', '18th century', 'renaissance', 'reformation', 'enlightenment'],
      'Modern (1800–1945)' => ['19th century', '20th century', 'world war', 'victorian', 'industrial revolution', 'colonial'],
      'Contemporary (1945–present)' => ['21st century', 'cold war', 'contemporary', 'modern music', 'post-war'],
    ];

    $catsStr = strtolower(implode(' ', $categories));
    foreach ($eraMap as $eraName => $keywords) {
      foreach ($keywords as $kw) {
        if (str_contains($catsStr, $kw)) {
          $terms = $termStorage->loadByProperties(['name' => $eraName, 'vid' => 'era']);
          if ($term = reset($terms)) return $term->id();
        }
      }
    }

    // Default to Timeless for science/nature topics.
    $terms = $termStorage->loadByProperties(['name' => 'Timeless', 'vid' => 'era']);
    if ($term = reset($terms)) return $term->id();
    return NULL;
  }

  protected function mapCategoriesToRegion(array $categories): ?int {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

    $regionMap = [
      'Africa' => ['africa', 'african', 'egypt', 'ethiopia', 'nigeria', 'kenya', 'zimbabwe'],
      'Americas' => ['america', 'united states', 'canada', 'mexico', 'brazil', 'latin', 'caribbean'],
      'Asia' => ['asia', 'asian', 'china', 'japan', 'india', 'korea', 'middle east', 'iran', 'iraq'],
      'Europe' => ['europe', 'european', 'british', 'french', 'german', 'italian', 'spanish', 'roman', 'greek'],
      'Oceania' => ['australia', 'new zealand', 'pacific', 'oceania'],
      'Antarctica' => ['antarctic', 'antarctica', 'south pole'],
      'Space' => ['space', 'astronomy', 'planet', 'star', 'galaxy', 'universe', 'cosmos'],
      'Global / Multiple Regions' => ['world', 'global', 'international', 'worldwide'],
    ];

    $catsStr = strtolower(implode(' ', $categories));
    foreach ($regionMap as $regionName => $keywords) {
      foreach ($keywords as $kw) {
        if (str_contains($catsStr, $kw)) {
          $terms = $termStorage->loadByProperties(['name' => $regionName, 'vid' => 'region']);
          if ($term = reset($terms)) return $term->id();
        }
      }
    }

    $terms = $termStorage->loadByProperties(['name' => 'Not Geographic', 'vid' => 'region']);
    if ($term = reset($terms)) return $term->id();
    return NULL;
  }

  protected function extractSeeAlso(string $html): array {
    $titles = [];
    if (preg_match('/<h2[^>]*>See also<\/h2>(.*?)(?:<h2|$)/si', $html, $section)) {
      preg_match_all('/<a[^>]+data-wiki-link="([^"]+)"[^>]*>/i', $section[1], $matches);
      foreach ($matches[1] as $link) {
        $titles[] = str_replace('_', ' ', urldecode($link));
      }
    }
    return $titles;
  }

  protected function generateWhyItMatters(string $title, string $lead, string $apiKey): ?string {
    $prompt = "In 2-3 sentences, explain why the Wikipedia article about '{$title}' matters in the broader context of human knowledge. "
      . "The article begins: \"{$lead}\". "
      . "Write for a curious general audience. Do not start with 'This article'.";

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
