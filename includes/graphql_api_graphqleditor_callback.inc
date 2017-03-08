<?php
function graphql_api_graphqleditor_callback() {
  drupal_add_js(drupal_get_path('module', 'graphql_api') . '/js/dist/main.bundle.js');
  return '<div id="graphqleditor"></div>';
}