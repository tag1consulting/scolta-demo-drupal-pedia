(function (Drupal, once) {
  'use strict';

  // Collapsible TOC
  Drupal.behaviors.athenaeumToc = {
    attach(context) {
      once('toc-toggle', '.toc-card__header', context).forEach(function (header) {
        header.addEventListener('click', function () {
          const body = this.nextElementSibling;
          const isCollapsed = body.style.display === 'none';
          body.style.display = isCollapsed ? '' : 'none';
          this.querySelector('.toc-toggle-icon').textContent = isCollapsed ? '−' : '+';
        });
      });
    }
  };

  // Hero search example queries
  Drupal.behaviors.athenaeumExampleQueries = {
    attach(context) {
      once('example-query', '[data-example-query]', context).forEach(function (link) {
        link.addEventListener('click', function (e) {
          e.preventDefault();
          const input = document.querySelector('.hero-search__input, input[name="q"]');
          if (input) {
            input.value = this.dataset.exampleQuery;
            input.focus();
          }
        });
      });
    }
  };

})(Drupal, once);
