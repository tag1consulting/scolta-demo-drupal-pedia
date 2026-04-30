<?php

/**
 * Setup Views for The Athenaeum.
 * Run with: ddev drush php:script import/setup-views.php
 */

use Drupal\views\Entity\View;

function save_view(array $config): void {
  $id = $config['id'];
  if (View::load($id)) {
    echo "View exists: $id\n";
    return;
  }
  View::create($config)->save();
  echo "Created view: $id\n";
}

// ============================================================
// ARTICLE LISTING — paginated browse page
// ============================================================
save_view([
  'id' => 'article_listing',
  'label' => 'Article Listing',
  'module' => 'views',
  'description' => 'Browsable, filterable list of all Featured Articles.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Browse Articles',
        'pager' => [
          'type' => 'full',
          'options' => ['items_per_page' => 24, 'offset' => 0, 'id' => 0, 'total_pages' => NULL, 'expose' => ['items_per_page' => FALSE]],
        ],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => FALSE, 'distinct' => FALSE, 'replica' => FALSE, 'query_comment' => '', 'query_tags' => []]],
        'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Apply', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => [
            'id' => 'status',
            'table' => 'node_field_data',
            'field' => 'status',
            'value' => '1',
            'group' => 1,
            'expose' => ['operator' => FALSE],
            'plugin_id' => 'boolean',
          ],
          'type' => [
            'id' => 'type',
            'table' => 'node_field_data',
            'field' => 'type',
            'value' => ['featured_article' => 'featured_article'],
            'group' => 1,
            'plugin_id' => 'bundle',
          ],
          'title' => [
            'id' => 'title',
            'table' => 'node_field_data',
            'field' => 'title',
            'operator' => 'contains',
            'value' => '',
            'exposed' => TRUE,
            'expose' => [
              'operator_id' => 'title_op',
              'label' => 'Search by title',
              'description' => '',
              'use_operator' => FALSE,
              'operator' => 'title_op',
              'operator_limit_selection' => FALSE,
              'operator_list' => [],
              'identifier' => 'title',
              'required' => FALSE,
              'remember' => FALSE,
              'multiple' => FALSE,
              'remember_roles' => ['authenticated' => 'authenticated'],
              'placeholder' => 'Filter by title…',
            ],
            'group' => 1,
            'plugin_id' => 'string',
          ],
        ],
        'sorts' => [
          'title' => [
            'id' => 'title',
            'table' => 'node_field_data',
            'field' => 'title',
            'order' => 'ASC',
            'expose' => ['label' => 'Title'],
            'exposed' => TRUE,
            'plugin_id' => 'standard',
          ],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'use_more' => FALSE,
        'use_more_always' => FALSE,
        'link_display' => 'page_1',
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_plugin' => 'page',
      'display_title' => 'All Articles',
      'position' => 1,
      'display_options' => [
        'path' => 'articles',
        'defaults' => [
          'title' => FALSE,
          'style' => FALSE,
          'row' => FALSE,
        ],
        'title' => 'All Featured Articles',
        'style' => ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE, 'uses_fields' => FALSE]],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
      ],
    ],
  ],
]);

// ============================================================
// RANDOM ARTICLE
// ============================================================
save_view([
  'id' => 'random_article',
  'label' => 'Random Article',
  'module' => 'views',
  'description' => 'Displays a random Featured Article.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Random Article',
        'pager' => ['type' => 'some', 'options' => ['items_per_page' => 1, 'offset' => 0]],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'none', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => ['disable_sql_rewrite' => FALSE, 'distinct' => FALSE, 'replica' => FALSE, 'query_comment' => '', 'query_tags' => []]],
        'exposed_form' => ['type' => 'basic', 'options' => []],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'value' => '1', 'group' => 1, 'expose' => ['operator' => FALSE], 'plugin_id' => 'boolean'],
          'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'value' => ['featured_article' => 'featured_article'], 'group' => 1, 'plugin_id' => 'bundle'],
        ],
        'sorts' => [
          'random' => ['id' => 'random', 'table' => 'views', 'field' => 'random', 'order' => 'ASC', 'plugin_id' => 'random'],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'use_more' => FALSE,
        'link_display' => 'page_1',
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_plugin' => 'page',
      'display_title' => 'Random Article Page',
      'position' => 1,
      'display_options' => [
        'path' => 'random',
        'defaults' => ['title' => FALSE],
        'title' => 'Random Article',
      ],
    ],
    'block_1' => [
      'id' => 'block_1',
      'display_plugin' => 'block',
      'display_title' => 'Featured Today Block',
      'position' => 2,
      'display_options' => [
        'defaults' => ['title' => FALSE],
        'title' => 'Today\'s Featured Article',
      ],
    ],
  ],
]);

// ============================================================
// BROWSE BY TOPIC
// ============================================================
save_view([
  'id' => 'browse_by_topic',
  'label' => 'Browse by Topic',
  'module' => 'views',
  'description' => 'Articles grouped by topic taxonomy.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Browse by Topic',
        'pager' => ['type' => 'full', 'options' => ['items_per_page' => 24, 'offset' => 0, 'id' => 0, 'total_pages' => NULL, 'expose' => ['items_per_page' => FALSE]]],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => []],
        'exposed_form' => ['type' => 'basic', 'options' => []],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'value' => '1', 'plugin_id' => 'boolean'],
          'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'value' => ['featured_article' => 'featured_article'], 'plugin_id' => 'bundle'],
        ],
        'sorts' => [
          'title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'order' => 'ASC', 'plugin_id' => 'standard'],
        ],
        'relationships' => [
          'field_topics_target_id' => [
            'id' => 'field_topics_target_id',
            'table' => 'node__field_topics',
            'field' => 'field_topics_target_id',
            'relationship' => 'none',
            'group_type' => 'group',
            'admin_label' => 'Topics',
            'label' => 'Topics',
            'required' => FALSE,
            'plugin_id' => 'standard',
          ],
        ],
        'arguments' => [
          'field_topics_target_id' => [
            'id' => 'field_topics_target_id',
            'table' => 'node__field_topics',
            'field' => 'field_topics_target_id',
            'relationship' => 'none',
            'group_type' => 'group',
            'admin_label' => 'Topics (field_topics)',
            'default_action' => 'default',
            'exception' => ['title' => 'All', 'default_argument_type' => 'fixed', 'default_argument_options' => ['argument' => ''], 'summary_options' => [], 'summary' => [], 'default_argument_skip_url' => TRUE],
            'default_argument_type' => 'fixed',
            'default_argument_options' => ['argument' => ''],
            'title_enable' => TRUE,
            'title' => 'Articles: %1',
            'default_empty' => FALSE,
            'must_not_be' => FALSE,
            'plugin_id' => 'numeric',
          ],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'use_more' => FALSE,
        'link_display' => 'page_1',
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_plugin' => 'page',
      'display_title' => 'Browse by Topic',
      'position' => 1,
      'display_options' => [
        'path' => 'topics',
        'defaults' => ['title' => FALSE],
        'title' => 'Browse by Topic',
      ],
    ],
  ],
]);

// ============================================================
// BROWSE BY ERA  (contextual argument on era term ID)
// ============================================================
save_view([
  'id' => 'browse_by_era',
  'label' => 'Browse by Era',
  'module' => 'views',
  'description' => 'Articles filtered by era via contextual argument.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Browse by Era',
        'pager' => ['type' => 'full', 'options' => ['items_per_page' => 24, 'offset' => 0, 'id' => 0, 'total_pages' => NULL, 'expose' => ['items_per_page' => FALSE]]],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => []],
        'exposed_form' => ['type' => 'basic', 'options' => []],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'value' => '1', 'plugin_id' => 'boolean'],
          'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'value' => ['featured_article' => 'featured_article'], 'plugin_id' => 'bundle'],
        ],
        'arguments' => [
          'field_era_target_id' => [
            'id' => 'field_era_target_id',
            'table' => 'node__field_era',
            'field' => 'field_era_target_id',
            'default_action' => 'default',
            'title_enable' => TRUE,
            'title' => '%1 Articles',
            'default_argument_type' => 'fixed',
            'default_argument_options' => ['argument' => ''],
            'plugin_id' => 'numeric',
          ],
        ],
        'sorts' => [
          'title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'order' => 'ASC', 'plugin_id' => 'standard'],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'link_display' => 'page_1',
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_plugin' => 'page',
      'display_title' => 'Browse by Era',
      'position' => 1,
      'display_options' => [
        'path' => 'eras',
        'defaults' => ['title' => FALSE],
        'title' => 'Browse by Era',
      ],
    ],
  ],
]);

// ============================================================
// BROWSE BY REGION  (contextual argument on region term ID)
// ============================================================
save_view([
  'id' => 'browse_by_region',
  'label' => 'Browse by Region',
  'module' => 'views',
  'description' => 'Articles filtered by region via contextual argument.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Browse by Region',
        'pager' => ['type' => 'full', 'options' => ['items_per_page' => 24, 'offset' => 0, 'id' => 0, 'total_pages' => NULL, 'expose' => ['items_per_page' => FALSE]]],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => []],
        'exposed_form' => ['type' => 'basic', 'options' => []],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'value' => '1', 'plugin_id' => 'boolean'],
          'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'value' => ['featured_article' => 'featured_article'], 'plugin_id' => 'bundle'],
        ],
        'arguments' => [
          'field_region_target_id' => [
            'id' => 'field_region_target_id',
            'table' => 'node__field_region',
            'field' => 'field_region_target_id',
            'default_action' => 'default',
            'title_enable' => TRUE,
            'title' => '%1 Articles',
            'default_argument_type' => 'fixed',
            'default_argument_options' => ['argument' => ''],
            'plugin_id' => 'numeric',
          ],
        ],
        'sorts' => [
          'title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'order' => 'ASC', 'plugin_id' => 'standard'],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'link_display' => 'page_1',
      ],
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_plugin' => 'page',
      'display_title' => 'Browse by Region',
      'position' => 1,
      'display_options' => [
        'path' => 'regions',
        'defaults' => ['title' => FALSE],
        'title' => 'Browse by Region',
      ],
    ],
  ],
]);

// ============================================================
// RECENT ARTICLES (for homepage)
// ============================================================
save_view([
  'id' => 'recent_articles',
  'label' => 'Recent Articles',
  'module' => 'views',
  'description' => 'Recently imported articles for homepage.',
  'tag' => 'athenaeum',
  'base_table' => 'node_field_data',
  'base_field' => 'nid',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => [
        'title' => 'Recent Articles',
        'pager' => ['type' => 'some', 'options' => ['items_per_page' => 6, 'offset' => 0]],
        'access' => ['type' => 'none', 'options' => []],
        'cache' => ['type' => 'tag', 'options' => []],
        'query' => ['type' => 'views_query', 'options' => []],
        'exposed_form' => ['type' => 'basic', 'options' => []],
        'row' => ['type' => 'entity:node', 'options' => ['relationship' => 'none', 'view_mode' => 'teaser']],
        'fields' => [],
        'filters' => [
          'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'value' => '1', 'plugin_id' => 'boolean'],
          'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'value' => ['featured_article' => 'featured_article'], 'plugin_id' => 'bundle'],
        ],
        'sorts' => [
          'nid' => ['id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid', 'order' => 'DESC', 'plugin_id' => 'standard'],
        ],
        'style' => ['type' => 'default', 'options' => []],
        'use_more' => TRUE,
        'use_more_always' => FALSE,
        'use_more_text' => 'Browse all articles',
        'link_display' => 'block_1',
      ],
    ],
    'block_1' => [
      'id' => 'block_1',
      'display_plugin' => 'block',
      'display_title' => 'Recent Articles Block',
      'position' => 1,
      'display_options' => [
        'defaults' => ['title' => FALSE],
        'title' => 'Recently Added',
      ],
    ],
  ],
]);

echo "\n=== Views setup complete ===\n";
echo "Created views: article_listing, random_article, browse_by_topic, browse_by_era, browse_by_region, recent_articles\n";
