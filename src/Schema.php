<?php
namespace Drupal\graphql_api;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Schema as GraphQLSchema;
use GraphQL\Type\Definition\ResolveInfo;

class Schema {

  private $interfaceTypes = [];
  private $objectTypes = [];
  private $graphqlInfo = [];

  public function __construct() {
    $this->graphqlInfo = graphql_api_info();
    foreach ($this->graphqlInfo['types'] as $type => $type_info) {
      if (is_callable($type_info)) {
        $this->objectTypes[$type] = call_user_func($type_info);
      }
    }
  }

  /**
   * Get entity info
   *
   * @param null $entity_type
   * @return array|mixed
   */
  public function getEntityInfo($entity_type = NULL) {
    $info = &drupal_static(__FUNCTION__);
    if (!$info) {
      $info = entity_get_info();
    }
    return $entity_type ? $info[$entity_type] : $info;
  }

  /**
   * Get entity property info
   *
   * @param null $entity_type
   * @return array|bool|mixed
   */
  public function getPropertyInfo($entity_type = NULL) {
    $info = &drupal_static(__FUNCTION__);
    if (!$info) {
      $info = entity_get_property_info();
    }
    return $entity_type ? (isset($info[$entity_type]) ? $info[$entity_type] : FALSE) : $info;
  }

  /**
   * Build GraphQL Schema
   *
   * @return \GraphQL\Schema
   */
  public function build() {
    $self = $this;
    $entity_types = $this->getEntityInfo();

    $graphData = [
      'interfaces' => [],
      'objects' => []
    ];
    foreach ($entity_types as $entity_type => $entity_type_info) {
      $fields = $this->getFields($entity_type);
      if (empty($fields)) continue;

      // define interface
      $graphData['interfaces'][$entity_type] = [
        'name' => $entity_type,
        'description' => isset($entity_type_info['description']) ? $entity_type_info['description'] : '',
        'fields' => [],
        'resolveType' => function ($obj) use($entity_type, &$self) {
          list($id, $rid, $bundle) = entity_extract_ids($entity_type, $obj);
          $objectType = $self->objectTypes[str_replace('-', '_', $entity_type .'__'. $bundle)];
          return $objectType;
        },
        '__entity_bundle' => null,
        '__entity_type' => $entity_type
      ];

      // define object
      foreach ($entity_type_info['bundles'] as $bundle => $bundle_info) {
        $machine_name = str_replace('-', '_', "{$entity_type}__{$bundle}");
        $fields = $this->getFields($entity_type, $bundle);
        if (empty($fields)) continue;

        $graphData['objects'][$machine_name] = [
          'name' => $machine_name,
          'description' => '',
          'fields' => [],
          'interfaces' => [],
          '__entity_bundle' => $bundle,
          '__entity_type' => $entity_type
        ];
      }
    }

    foreach ($graphData['interfaces'] as $key => $item) {
      $fields = $this->getFields($item['__entity_type'], $item['__entity_bundle']);
      $this->addInterfaceType($key, ['fields' => $fields] + $item);
    }

    foreach ($graphData['objects'] as $key => $item) {
      $type = $item['__entity_type'];
      $bundle = $item['__entity_bundle'];
      $fields = $this->getFields($type, $bundle);
      $this->addObjectType($key, ['fields' => $fields, 'interfaces' => [$this->interfaceTypes[$type]]] + $item);
    }

    $queryTypeInfo = ['name' => 'Query'];
    foreach ($this->interfaceTypes as $type => $interface) {
      $queryTypeInfo['fields'][$type] = [
        'type' => $interface,
        'args' => [
          'id' => [
            'name' => 'id',
            'description' => 'id of ' . $type,
            'type' => Type::string()
          ]
        ],
        'resolve' => function ($root, $args) {
          return [];
        },
      ];
    }

    foreach ($this->objectTypes as $type => $object) {
      $queryTypeInfo['fields'][$type] = [
        'type' => Type::listOf($object),
        'args' => [
          'id' => [
            'name' => 'id',
            'description' => 'id of ' . $type,
            'type' => Type::string()
          ]
        ],
        'resolve' => function ($root, $args) use ($object) {
          $type = $object->config['__entity_type'];
          $bundle = $object->config['__entity_bundle'];
          $op = 'view';
          $query = graphql_api_entity_get_query($type, $args);
          $query->range(0, 30);
          $result = $query->execute();
          if (!empty($result[$type])) {
            $entities = entity_load($type, array_keys($result[$type]));
            $entities = array_filter($entities, function ($entity) use($op, $type) {
              return entity_access($op, $type, $entity);
            });
            return $entities;
          }
          return null;
        },
      ];
    }

    $queryType = new ObjectType($queryTypeInfo);

    // TODOC
    $mutationType = null;

    $schema = new GraphQLSchema([
      'query' => $queryType,
      'mutation' => $mutationType,

      // We need to pass the types that implement interfaces in case the types are only created on demand.
      // This ensures that they are available during query validation phase for interfaces.
      'types' => $this->objectTypes + $this->interfaceTypes
    ]);

    return $schema;
  }

  /**
   * Add GraphQL interface
   *
   * @param $name
   * @param $info
   */
  public function addInterfaceType($name, $info) {
    $this->interfaceTypes[$name] = new InterfaceType($info);
  }

  /**
   * Add GraphQL object
   *
   * @param $name
   * @param $info
   */
  public function addObjectType($name, $info) {
    $this->objectTypes[$name] = new ObjectType($info);
  }

  /**
   * Get GraphQL fields definition for given entity type / bundle
   *
   * @param $entity_type
   * @param string $bundle
   * @return array
   */
  public function getFields($entity_type, $bundle = '') {
    $fields = [];
    $properties_info = $this->getPropertyInfo($entity_type);
    if (!$properties_info) return $fields;

    $entity_info = $this->getEntityInfo($entity_type);
    $entity_keys = $entity_info['entity keys'];

    foreach ($properties_info['properties'] as $property => $info) {
      $fieldType = isset($info['type']) ? $this->gqlFieldType($info['type'], ['entity_type' => $entity_type, 'bundle' => null, 'property' => $property, 'info' => $info]) : Type::string();
      if (!$fieldType) continue;
      $fields[$property] = [
        'type' => $fieldType,
        'description' => isset($info['description']) ? $info['description'] : ''
      ];
      if (in_array($property, [$entity_keys['id'], $entity_keys['revision']])) {
        $fields[$property]['type'] = Type::id();
      }
    }

    if ($bundle && !empty($properties_info['bundles'][$bundle])) {
      foreach ($properties_info['bundles'][$bundle]['properties'] as $field => $field_info) {
        $fieldType = $this->gqlFieldType($field_info['type'], ['entity_type' => $entity_type, 'bundle' => $bundle, 'property' => $field, 'info' => $field_info]);
        if (!$fieldType) continue;
        $fields[$field] = [
          'type' => $fieldType,
          'description' => $field_info['description'],
          'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $field) {
            $items = $value->wrapper()->{$field}->value();
            return $items;
          }
        ];
      }
    }

    return $fields;
  }

  /**
   *
   *
   * @param $drupalType
   * @param array $context
   * @return bool|\Closure|\GraphQL\Type\Definition\ListOfType|mixed
   */
  public function gqlFieldType($drupalType, $context = []) {
    // handle list type
    if (preg_match('/list<([a-z-_]+)>/i', $drupalType, $matches)) {
      $matchType = $matches[1];
      if ($gqlType = $this->drupalToGqlFieldType($matchType, $context)) {
        return Type::listOf($gqlType);
      }
      if (isset($this->interfaceTypes[$matchType])) {
        return Type::listOf($this->interfaceTypes[$matchType]);
      }
    }

    return $this->drupalToGqlFieldType($drupalType, $context);
  }

  /**
   * Map Drupal property type into GraphQL type
   *
   * @param $drupalType
   * @param array $context
   * @return bool|\Closure|\GraphQL\Type\Definition\BooleanType|\GraphQL\Type\Definition\FloatType|\GraphQL\Type\Definition\IntType|\GraphQL\Type\Definition\ListOfType|\GraphQL\Type\Definition\StringType|mixed
   */
  public function drupalToGqlFieldType($drupalType, $context = []) {
    // check custom types
    switch ($drupalType) {
      case 'text':
        return Type::string();
      case 'int':
      case 'integer':
        return Type::int();
      case 'decimal':
        return Type::float();
      case 'boolean':
        return Type::boolean();
      case 'date':
        return Type::int();
      case 'uri':
        return Type::string();
      case 'token':
        return Type::string();
      default:
        if (isset($this->interfaceTypes[$drupalType])) {
          return $this->interfaceTypes[$drupalType];
        } else if (isset($this->objectTypes[$drupalType])) {
          return $this->objectTypes[$drupalType];
        } else if(isset($context['info']['type'])) {
          return $this->gqlFieldType($context['info']['type']);
        }
        return false;
    }
  }

}