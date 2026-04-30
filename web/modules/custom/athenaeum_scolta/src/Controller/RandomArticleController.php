<?php

declare(strict_types=1);

namespace Drupal\athenaeum_scolta\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RandomArticleController extends ControllerBase {

  public function handle(): RedirectResponse {
    $nids = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', 'featured_article')
      ->condition('status', 1)
      ->execute();

    if (empty($nids)) {
      return new RedirectResponse('/');
    }

    $nid = $nids[array_rand($nids)];
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    $url = $node ? $node->toUrl()->toString() : '/';

    $response = new RedirectResponse($url, 302);
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    return $response;
  }

}
