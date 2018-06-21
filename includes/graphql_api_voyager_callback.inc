<?php
function graphql_api_voyager_callback() {
  drupal_add_js('https://cdn.jsdelivr.net/react/15.4.2/react.min.js');
  drupal_add_js('https://cdn.jsdelivr.net/react/15.4.2/react-dom.min.js');
  drupal_add_css('https://apis.guru/graphql-voyager/releases/v1.x/voyager.css', 'external');
  drupal_add_js('https://cdn.jsdelivr.net/npm/graphql-voyager@1.0.0-rc.9/dist/voyager.min.js');
  drupal_add_js(GRAPHQL_API_PATH . '/js/legacy/voyager.js');
  drupal_add_css(GRAPHQL_API_PATH . '/css/voyager.css');

  return '<div id="graphql-api-voyager"></div>';
}