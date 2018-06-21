<?php
function graphql_api_graphiql_callback() {
  drupal_add_js(GRAPHQL_API_PATH . '/js/dist/main.bundle.js');
  drupal_add_css(GRAPHQL_API_PATH . '/css/graphiql.css');
  drupal_add_js([
    'graphql' => [
      'endpoint' => url('graphql')
    ]
  ], 'setting');
  return '<div id="graphql-api-graphiql"></div>';
}