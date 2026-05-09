<?php

/**
 * @file
 * Post-update hooks for the Athenaeum Import module.
 */

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;

/**
 * Attach missing lead images for articles skipped due to Wikimedia rate limits.
 *
 * The initial bulk import hit HTTP 429 (Too Many Requests) on the final batch,
 * leaving two articles without images:
 *   - Nikita Zotov (nid 6908): image file was already on disk from a prior
 *     download attempt but had no corresponding file entity.
 *   - Zungeni Mountain skirmish (nid 6910): image was never downloaded.
 */
function athenaeum_import_post_update_attach_rate_limited_images(array &$sandbox): void {
  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
  $fileRepository = \Drupal::service('file.repository');
  $fileSystem = \Drupal::service('file_system');
  $logger = \Drupal::logger('athenaeum_import');

  $userAgent = 'TheAthenaeum/1.0 (https://github.com/tag1consulting/scolta-demos; scolta@tag1consulting.com)';

  $targets = [
    6908 => [
      'title' => 'Nikita Zotov',
      'local' => 'public://article-images/nikita-zotov.JPG',
      'remote' => 'https://upload.wikimedia.org/wikipedia/commons/8/8e/Zotov2.JPG',
      'ext' => 'JPG',
    ],
    6910 => [
      'title' => 'Zungeni Mountain skirmish',
      'local' => 'public://article-images/zungeni-mountain-skirmish.jpeg',
      'remote' => 'https://upload.wikimedia.org/wikipedia/commons/e/e4/Death_of_Lt_Frith_at_Zungeni_Mountain_%28The_Illustrated_London_News%2C_1879%29.jpeg',
      'ext' => 'jpeg',
    ],
  ];

  $ctx = stream_context_create(['http' => [
    'header' => 'User-Agent: ' . $userAgent,
    'timeout' => 30,
    'ignore_errors' => TRUE,
  ]]);

  foreach ($targets as $nid => $info) {
    $node = $nodeStorage->load($nid);
    if (!$node || $node->field_image->target_id) {
      continue;
    }

    $dir = 'public://article-images';
    $fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);

    // Use the local file if it already exists (avoids a remote download).
    $realPath = $fileSystem->realpath($info['local']);
    if ($realPath && file_exists($realPath)) {
      $imageData = file_get_contents($realPath);
    }
    else {
      // Download from Wikimedia with retry on 429 rate-limit responses.
      $imageData = FALSE;
      foreach ([0, 5, 15] as $delaySecs) {
        if ($delaySecs > 0) {
          sleep($delaySecs);
        }
        $imageData = @file_get_contents($info['remote'], FALSE, $ctx);
        if ($imageData === FALSE) {
          break;
        }
        $statusLine = ($http_response_header ?? [''])[0];
        if (strpos($statusLine, '429') === FALSE) {
          break;
        }
        $imageData = FALSE;
      }
    }

    if (!$imageData) {
      $logger->warning('athenaeum_import_post_update: could not obtain image for "@title" (nid @nid).', [
        '@title' => $info['title'],
        '@nid' => $nid,
      ]);
      continue;
    }

    $fileEntity = $fileRepository->writeData($imageData, $info['local'], FileExists::Replace);
    if (!$fileEntity) {
      $logger->warning('athenaeum_import_post_update: failed to create file entity for "@title" (nid @nid).', [
        '@title' => $info['title'],
        '@nid' => $nid,
      ]);
      continue;
    }

    $node->set('field_image', ['target_id' => $fileEntity->id(), 'alt' => $info['title']]);
    $node->save();
    $logger->info('athenaeum_import_post_update: attached image fid @fid to "@title" (nid @nid).', [
      '@fid' => $fileEntity->id(),
      '@title' => $info['title'],
      '@nid' => $nid,
    ]);
  }
}
