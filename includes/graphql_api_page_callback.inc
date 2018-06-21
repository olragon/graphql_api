<?php
use GraphQL\GraphQL;
use GraphQL\Utils\SchemaPrinter;

/**
 * Page callback for /graphql
 */
function graphql_api_page_callback() {
  $_startTime = microtime(TRUE);
  $is_introspec = FALSE;
  $is_batch = FALSE;
  $variables = [];
  if (isset($_SERVER['CONTENT_TYPE']) && strpos(strtolower($_SERVER['CONTENT_TYPE']), 'application/json') > -1) {
    $rawBody = file_get_contents('php://input');
    $data = drupal_json_decode($rawBody ?: '');
  } else {
    $data = $_POST;
  }

  $requestString = isset($data['query']) ? $data['query'] : null;
  $operationName = isset($data['operationName']) ? $data['operationName'] : null;
  $variableValues = isset($data['variables']) ? $data['variables'] : [];
  $is_mutation = true;
  if ($operationName === 'Introspection') {
    $operationName = null;
  }

  try {
    $result = [];
    $is_batched = false;
    // Define your schema:
    $_startBuildTime = microtime(true);

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

    $_endBuildTime = microtime(true) - $_startBuildTime;

    $_startQueryTime = microtime(true);

    // execute single query
    if ($requestString) {
      $result = GraphQL::executeQuery(
        $schema,
        $requestString,
        /* $rootValue */ null,
        /* $context */ null, // A custom context that can be used to pass current User object etc to all resolvers.
        $variableValues,
        $operationName
      )->toArray(true);
    } else if (count($data) > 0 && !empty($data[0]['query']) && !empty($data[0]['operationName'])) {
      $is_batched = true;
      foreach ($data as $index => $batch_query) {
        $batch_query['variables'] = isset($batch_query['variables']) ? $batch_query['variables'] : [];
        $batch_result = GraphQL::executeQuery(
          $schema,
          $batch_query['query'],
          /* $rootValue */ null,
          /* $context */ null, // A custom context that can be used to pass current User object etc to all resolvers.
          $batch_query['variables'],
          $batch_query['operationName']
        );
        $result[$index] = $batch_result->toArray(true);
      }
    }

    $_endQueryTime = microtime(true) - $_startQueryTime;

    $debug = [
        'schemaBuildTime' => $_endBuildTime,
        'queryTime' => $_endQueryTime,
        'totalTime' => microtime(true) - $_startTime,
      ] + $schemaBuilder->getMetrics();

    if (!$is_batched) {
      $result['debug'] = $debug;
    }

    if (variable_get('graphql_debug')) {
      watchdog('GraphQL', 'Execute query ' . json_encode($data, JSON_PRETTY_PRINT) . "\nDebug: " . json_encode($debug, JSON_PRETTY_PRINT));
    }

    drupal_json_output($result);
    exit;
  } catch (Exception $exception) {
    dump($exception);
    die();
    $result = [
      'errors' => [
        ['message' => $exception->getMessage(), 'backtrace' => debug_backtrace()]
      ]
    ];
  }
  if (!empty($_GET['raw'])) {
    echo $result;
    exit;
  }

  drupal_json_output($result);
  exit;
}
