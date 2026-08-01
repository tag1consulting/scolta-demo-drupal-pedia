/**
 * @file
 * Rich search result cards for The Athenaeum.
 *
 * Registers a Scolta result renderer that paints an article's lead-image
 * thumbnail, era and region badges alongside the title and highlighted
 * excerpt. Everything it needs comes from the search index — the thumbnail
 * URL and the badge strings ride along in the fragment's meta map, put there
 * by athenaeum_scolta_scolta_content_item_alter() — so a card costs no
 * per-result server call.
 *
 * Load order matters. scolta.js defines window.Scolta when it executes and
 * Drupal's scolta bridge behavior calls Scolta.init() on DOMContentLoaded, so
 * this file must run after the former and before the latter. Declaring
 * scolta/search as a dependency and leaving the library in the footer puts it
 * exactly there; registering at top level (not inside a DOMContentLoaded
 * handler) keeps it there.
 */
(function (global) {
  'use strict';

  if (!global.Scolta || typeof global.Scolta.setResultRenderer !== 'function') {
    // A bundle without the render seam is not something to work around here.
    console.warn('[athenaeum] Scolta.setResultRenderer unavailable; leaving the built-in card in place.');
    return;
  }

  var ENTITIES = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  };

  function escapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/[&<>"']/g, function (c) { return ENTITIES[c]; });
  }

  /**
   * Escapes a URL for an attribute and neutralizes non-http(s) schemes.
   *
   * The thumbnail URL is written by the indexer as a root-relative path, but
   * it arrives here as raw index data, so it gets the same treatment Scolta
   * gives the result href rather than an assumption about who wrote it.
   */
  function safeImageUrl(value) {
    var url = String(value === null || value === undefined ? '' : value).trim();
    if (url === '') {
      return '';
    }
    if (/^[a-z][a-z0-9+.-]*:/i.test(url) && !/^https?:/i.test(url)) {
      return '';
    }
    return escapeHtml(url);
  }

  /**
   * Drops the thumbnail when its image fails to load.
   *
   * Adding a class rather than removing the node keeps the handler cheap and
   * lets the stylesheet decide what an imageless rich card looks like.
   */
  global.athenaeumScoltaThumbFailed = function (img) {
    var card = img.closest ? img.closest('.athenaeum-result') : null;
    if (card) {
      card.classList.add('athenaeum-result--thumb-failed');
    }
  };

  function badge(label) {
    return '<span class="athenaeum-result__badge">' + escapeHtml(label) + '</span>';
  }

  /**
   * Renders one result.
   *
   * Escaping: every ctx value used here ends in Html, Attr or Text, or is
   * safeUrl, so Scolta has already escaped it exactly as its own card would.
   * Everything read from data.meta — image, image_alt, era, region — is raw
   * index data and is escaped here. ctx.query and ctx.highlightTerms are raw
   * and never reach the markup.
   *
   * An article with no lead image gets the same card without the thumbnail,
   * not Scolta's built-in one. Only about a third of this corpus carries an
   * image, and mixing the two card designs in one list reads as a broken page
   * rather than a designed fallback.
   */
  global.Scolta.setResultRenderer(function (data, ctx) {
    var meta = (data && data.meta) || {};
    var imageUrl = safeImageUrl(meta.image);
    var alt = escapeHtml(meta.image_alt || '');
    var badges = '';
    if (meta.era) {
      badges += badge(meta.era);
    }
    if (meta.region) {
      badges += badge(meta.region);
    }

    var metaRow = '';
    if (ctx.dateHtml || badges) {
      metaRow = '<div class="athenaeum-result__meta">'
        + (ctx.dateHtml ? '<span class="athenaeum-result__date">' + ctx.dateHtml + '</span>' : '')
        + badges
        + '</div>';
    }

    // The thumbnail is decorative: the title link beside it goes to the same
    // place, so it stays out of the tab order and out of the accessible tree.
    var thumb = imageUrl === '' ? ''
      : '<a class="athenaeum-result__thumb" href="' + ctx.safeUrl + '" target="_blank" rel="noopener"'
        + ' tabindex="-1" aria-hidden="true">'
        + '<img src="' + imageUrl + '" alt="' + alt + '" loading="lazy" decoding="async"'
        + ' onerror="athenaeumScoltaThumbFailed(this)">'
        + '</a>';

    // target/rel match the built-in card: within one result list, a card with
    // a thumbnail must not open differently from one without.
    return '<div class="scolta-result-card athenaeum-result">'
      + thumb
      + '<div class="athenaeum-result__body">'
      + '<a class="scolta-result-title athenaeum-result__title" href="' + ctx.safeUrl + '"'
      + ' target="_blank" rel="noopener" title="' + ctx.titleAttr + '">' + ctx.titleHtml + '</a>'
      + metaRow
      + '<div class="scolta-result-excerpt athenaeum-result__excerpt">' + ctx.excerptHtml + '</div>'
      + '</div>'
      + '</div>';
  });

})(window);
