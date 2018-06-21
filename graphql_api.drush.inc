<?php
/**
 * Implements hook_drush_command().
 */
function graphql_api_drush_command() {
  $items['graphql-dump-introspection'] = [
    'description' => "Dump introspect result",
    'arguments'   => [],
    'aliases'     => ['gadi'],
    'examples'    => [
      'drush graphql-api-dump-introspect' => 'Dump introspect result',
    ],
  ];
  $items['graphql-dump-schema'] = [
    'description' => "Dump schema to schema.graphql",
    'arguments'   => [],
    'aliases'     => ['gds'],
    'examples'    => [
      'drush graphql-dump-schema' => 'Dump schema.graphql',
    ],
  ];
  $items['graphql-dump-ast'] = [
    'description' => "Dump ast to schema.ast.php",
    'arguments'   => [],
    'aliases'     => ['gds'],
    'examples'    => [
      'drush graphql-dump-ast' => 'Dump schema.ast.php',
    ],
  ];
  return $items;
}

function _drush_graphql_api_get_schema() {
  $is_mutation = true;
  $typeLoader = function($typeName) use($is_mutation) {
    $schemaBuilder = graphql_api();
    $type = $schemaBuilder->buildType($typeName, $is_mutation);
    if ($type) {
      return $type;
    }
    return null;
  };
  $schemaBuilder = graphql_api();
  $schema = $schemaBuilder->build([
    'typeLoader' => $typeLoader
  ], $is_mutation);
  return $schema;
}

function drush_graphql_api_graphql_dump_introspection() {
  $result = graphql_api_query_file(GRAPHQL_API_PATH . '/tests/fixtures/instrospec.graphql');
  file_unmanaged_save_data(json_encode($result), GRAPHQL_API_PATH . '/introspection.json', FILE_EXISTS_OVERWRITE);
}

function drush_graphql_api_graphql_dump_schema() {
  $schema_file = GRAPHQL_API_PATH . '/schema.graphql';
  $schema = _drush_graphql_api_get_schema();

  $schema_graphql = GraphQL\Utils\SchemaPrinter::doPrint($schema);
  file_unmanaged_save_data($schema_graphql, $schema_file, FILE_EXISTS_OVERWRITE);
  drush_print('schema.graphql ready');
}

function drush_graphql_api_graphql_dump_ast() {
  $schema_file = __DIR__ . '/schema.graphql';
  $ast_file = __DIR__ . '/schema.ast.php';
  if (!file_exists($schema_file)) {
    return drush_log("$schema_file is missing.", 'error');
  }
  $schema_content = file_get_contents($schema_file);
  $ast = GraphQL\Language\Parser::parse($schema_content);
  $ast_array = GraphQL\Utils\AST::toArray($ast);
  $ast_data = "<?php\nreturn " . var_export(GraphQL\Utils\AST::toArray($ast_array), true);
  file_put_contents($ast_file, $ast_data);
  drush_print('schema.ast.php ready');
}

