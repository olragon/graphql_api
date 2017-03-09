GraphQL API for Drupal 7
------------------------

[![Build Status](https://travis-ci.org/olragon/graphql_api.svg?branch=master)](https://travis-ci.org/olragon/graphql_api)

![GraphQL API with GraphiQL](/screenshot.png?raw=true "GraphQL API with GraphiQL")

The problems
------------

All the cool thing seems to be happening on Drupal 8 which are includes [GraphQL].

This module attempt bring GraphQL to Drupal 7.

For Drupal 8, you should use <http://drupal.org/project/graphql>


Requirements
------------

- PHP 5.4+

Installation
------------

- Install `xautoload` http://drupal.org/project/xautoload
- Install `entity` http://drupal.org/project/entity
- Install `composer_manager` http://drupal.org/project/composer_manager
- Install `graphql_api`
- Update composer requirement's https://www.drupal.org/node/2405805

Usages
------

- `/graphqleditor` explore your Drupal 7 site's GraphQL schema
- `/graphql` use your favorite GraphQL client (Apollo http://www.apollodata.com/) to begin query
- `graphql_api_query_file()` execute .GrahpQL query from file

The tools
---------

- GraphQL - <http://graphql.org/>
- graphql-php - <https://github.com/webonyx/graphql-php>
- Drupal 7 - <https://www.drupal.org/project/drupal>
- Entity API - <https://www.drupal.org/project/entity>
- Composer Manager - <https://www.drupal.org/project/composer_manager>
- X Autoload - <https://www.drupal.org/project/xautoload>
- GraphIQL Feen - [https://chrome.google.com/webstore/detail/graphiql-feen/mcbfdonlkfpbfdpi…]

The plans
---------

1. [x] Create module graphql\_api
2. [x] Create class `Drupal\grapql\_api\Schema` to build GraphQL schema
    - use `hook_entity_info()`, `hook_entity_property_info()` to build GraphQL schema
    - map Drupal concept to GraphQL concept
        - Entity type -> Interface
        - Entity bundle -> Object
        - Entity revision -> Object
        - Property, Field API -> Property
    - resolve relationship use entity metadata info
        - Base field uid -> Inteface: user
        - Base field rid -> Object: revision
        - Field API: term_reference -> Interface: term
        - Field API: entityreference -> Interface/Object target entity
        - Field API: relation -> Interface/Object target entity
3. [x] Create GraphQL endpoint `/graphql`
    - receive POST content with GraphQL query and variables
    - query using Drupal's `EntityFieldQuery`
    - check entity access using `entity_access`
    - resolve property using `entity_metadata_wrapper`
    - return result

  [GraphQL]: https://www.drupal.org/project/graphql "GraphQL module"
  [https://chrome.google.com/webstore/detail/graphiql-feen/mcbfdonlkfpbfdpi…]: https://chrome.google.com/webstore/detail/graphiql-feen/mcbfdonlkfpbfdpimkjilhdneikhfklp

Notes
-----

1. ~~Field will be shortened `field_tags` -> `tags`~~ Shortened field name can be duplicate with base table's column or other property. Keep field name intact.
2. ~~If entity type have single bundle, we skip GraphQL interface and just use GraphQL object. Eg: user, file, ...~~ Entity reference field will resolve to Entity type, not bundle.
