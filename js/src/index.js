import React from 'react';
import ReactDOM from 'react-dom';
import GraphiQL from 'graphiql';
import fetch from 'isomorphic-fetch';
import 'graphiql/graphiql.css';
import '../../css/graphqleditor.css';

function boot() {

  ReactDOM.render(<GraphiQL fetcher={graphQLFetcher} />, document.getElementById('graphqleditor'));

  function graphQLFetcher(graphQLParams) {
    return fetch(Drupal.settings.basePath + Drupal.settings.pathPrefix + 'graphql', {
      credentials: 'include',
      method: 'post',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(graphQLParams),
    }).then(response => response.json());
  }
}

Drupal.behaviors.graphql_api = {
  attach: boot
};

