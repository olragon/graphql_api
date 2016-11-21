GraphQL API for Drupal 7
------------------------

[![Build Status](https://travis-ci.org/olragon/graphql_api.svg?branch=master)](https://travis-ci.org/olragon/graphql_api)

The problems
------------

All the cool thing seems to be happening on Drupal 8 which are includes [GraphQL].

This module attempt bring GraphQL to Drupal 7.

For Drupal 8, you should use <http://drupal.org/project/graphql>

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

1. Create module graphql\_api
2. Create class `Drupal\grapql\_api\Schema` to build GraphQL schema
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
3. Create GraphQL endpoint `/graphql`
    - receive POST content with GrapQL query and variables
    - execute query and return result

  [GraphQL]: https://www.drupal.org/project/graphql "GraphQL module"
  [https://chrome.google.com/webstore/detail/graphiql-feen/mcbfdonlkfpbfdpi…]: https://chrome.google.com/webstore/detail/graphiql-feen/mcbfdonlkfpbfdpimkjilhdneikhfklp
