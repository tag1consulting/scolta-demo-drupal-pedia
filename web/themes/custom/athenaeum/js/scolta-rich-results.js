/**
 * @file
 * Rich search result cards for The Athenaeum.
 *
 * Registers two Scolta renderers: a result renderer that paints an article's
 * lead-image thumbnail and topic badges alongside the title and highlighted
 * excerpt, and a suggestion renderer that puts the same thumbnail on the
 * search-as-you-type rows. Everything they need comes from the search index —
 * the thumbnail URL and the topic labels ride along in the fragment's meta
 * map, put there by athenaeum_scolta_scolta_content_item_alter() — so neither
 * a card nor a suggestion costs a per-result server call.
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
   * How many topic badges a card paints. Mirrors the indexer's own cap, which
   * is what actually bounds the string; this is the client-side belt to it.
   */
  var TOPIC_BADGE_LIMIT = 3;

  /**
   * Renders an article's topic badges.
   *
   * data.meta.topics is raw index data: a JSON-encoded array of term labels,
   * already capped by athenaeum_scolta_scolta_content_item_alter(). JSON and
   * not a delimited string because a term label is free text, so there is no
   * separator a future label provably cannot contain.
   *
   * Anything that does not parse into an array counts as no topics. An article
   * without topics simply shows no badges — the same graceful path a missing
   * image takes, not a broken card.
   *
   * The card badges topics rather than era and region because on this corpus
   * those two are degenerate: nearly every article is "Timeless" and "Not
   * Geographic", so the old badge pair repeated itself down the whole list.
   * Both remain facets in the filter panel.
   */
  function topicBadges(encoded) {
    if (!encoded) {
      return '';
    }
    var labels;
    try {
      labels = JSON.parse(encoded);
    } catch (e) {
      return '';
    }
    if (!Array.isArray(labels)) {
      return '';
    }
    var out = '';
    for (var i = 0; i < labels.length && i < TOPIC_BADGE_LIMIT; i++) {
      var label = String(labels[i] === null || labels[i] === undefined ? '' : labels[i]).trim();
      if (label !== '') {
        out += badge(label);
      }
    }
    return out;
  }

  /**
   * Renders one result.
   *
   * Escaping: every ctx value used here ends in Html, Attr or Text, or is
   * safeUrl, so Scolta has already escaped it exactly as its own card would.
   * Everything read from data.meta — image, image_alt, topics — is raw index
   * data and is escaped here. ctx.query and ctx.highlightTerms are raw and
   * never reach the markup.
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
    var badges = topicBadges(meta.topics);

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

  // Everything below is behind its own guard rather than the file-level one:
  // this seam landed after setResultRenderer, so a bundle old enough to lack
  // it still gets the rich cards above, and the dropdown degrades to the
  // themed but imageless rows instead of throwing.
  if (typeof global.Scolta.setSuggestionRenderer !== 'function') {
    return;
  }

  /**
   * Empties a suggestion thumbnail whose image fails to load.
   *
   * The box stays and becomes the same invisible spacer an imageless row uses,
   * rather than being removed: dropping it would pull the row's text leftwards
   * out of line with its neighbours, which is a worse artifact than a blank
   * gap. Nothing else in the row moves, so a 404 costs no layout shift.
   */
  global.athenaeumScoltaSaytThumbFailed = function (img) {
    var box = img.closest ? img.closest('.athenaeum-sayt__thumb') : null;
    if (box) {
      box.removeChild(img);
      box.classList.add('athenaeum-sayt__thumb--empty');
    }
  };

  /**
   * Renders one search-as-you-type suggestion row.
   *
   * Returns the row's INNER markup only. The option element around it is the
   * bundle's, and it is what carries the combobox contract — role="option",
   * the stable id the input's aria-activedescendant points at, aria-selected,
   * the data-scolta-sayt-index the keyboard and click handlers dispatch on,
   * and the href in navigate mode. None of that is restated here, because a
   * renderer cannot break by omission what it never writes.
   *
   * Escaping: ctx.titleHtml and ctx.excerptHtml arrive pre-escaped, escaped
   * exactly as the built-in row escapes them. suggestion.meta.* is raw index
   * data and is escaped here. ctx.query is raw and never reaches the markup.
   *
   * A recent search is handed back to the built-in row by returning a non
   * string: it has no fragment, no image and nothing to add, and the built-in
   * row is already the themed glyph treatment this dropdown wants for history.
   * A title suggestion with no image gets this same row minus the thumbnail,
   * never the built-in one — mixing two row designs in one list reads as a
   * broken dropdown rather than a designed fallback, the lesson the cards
   * already learned.
   */
  global.Scolta.setSuggestionRenderer(function (suggestion, ctx) {
    if (!suggestion || suggestion.type !== 'title') {
      return null;
    }

    var meta = suggestion.meta || {};
    var imageUrl = safeImageUrl(meta.image);

    // Decorative, and deliberately not carrying meta.image_alt: an option's
    // accessible name is computed from its contents, so alt text here would be
    // announced in front of the title it illustrates — "Portrait of Napoleon,
    // Napoleon". The title beside it already names the row.
    //
    // A title suggestion with no image still gets the box, empty and with its
    // border and fill removed. Only about a third of this corpus carries an
    // image, so without the spacer a dropdown mixes indented and flush-left
    // rows and stops reading as one list — the same reason the cards do not
    // fall back to a second design. An invisible spacer buys that alignment
    // without painting an empty grey square for the two rows in five that
    // have nothing to show.
    var thumb = imageUrl === ''
      ? '<span class="athenaeum-sayt__thumb athenaeum-sayt__thumb--empty" aria-hidden="true"></span>'
      : '<span class="athenaeum-sayt__thumb" aria-hidden="true">'
        + '<img src="' + imageUrl + '" alt="" loading="lazy" decoding="async"'
        + ' onerror="athenaeumScoltaSaytThumbFailed(this)">'
        + '</span>';

    return '<span class="athenaeum-sayt">'
      + thumb
      // Both classes on purpose. The scolta-* one carries the look the theme
      // already gives a suggestion's title and excerpt, so a title row and a
      // recent-search row stay typographically identical; the athenaeum-* one
      // adds only the layout this row needs. Two classes at the same
      // specificity, resolved by source order, rather than a nested selector.
      + '<span class="athenaeum-sayt__text">'
      + '<span class="scolta-sayt-title athenaeum-sayt__title">' + ctx.titleHtml + '</span>'
      + (ctx.excerptHtml
        ? '<span class="scolta-sayt-excerpt athenaeum-sayt__excerpt">' + ctx.excerptHtml + '</span>'
        : '')
      + '</span>'
      + '</span>';
  });

})(window);
