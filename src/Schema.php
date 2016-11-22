<?php
namespace Drupal\graphql_api;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\IDType;
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
      } else {
        $this->objectTypes[$type] = $type_info;
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

    foreach ($entity_types as $entity_type => $entity_type_info) {
      // define interface
      $this->addEntityGqlDefinition($entity_type);
    }

    $queryTypeInfo = ['name' => 'Query'];
    foreach ($this->interfaceTypes as $type => $interface) {
      if (!isset($interface->config['__entity_type'])) {
        continue;
      }
      $queryTypeInfo['fields'][$type] = [
        'type' => Type::listOf($interface),
        'args' => $this->entityToGqlQueryArg($interface->config['__entity_type']),
        'resolve' => function ($root, $args) use ($type) {
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
        },
      ];
    }

    foreach ($this->objectTypes as $type => $object) {
      if (!isset($object->config['__entity_type']) || !isset($object->config['__entity_bundle'])) {
        continue;
      }
      $queryTypeInfo['fields'][$type] = [
        'type' => Type::listOf($object),
        'args' => $this->entityToGqlQueryArg($object->config['__entity_type'], $object->config['__entity_bundle']),
        'resolve' => function ($root, $args) use ($object, $self) {
          $type = $object->config['__entity_type'];
          $bundle = $object->config['__entity_bundle'];
          $info = $self->getEntityInfo($type);

          if (isset($info['entity keys']['bundle']) && !isset($args[$info['entity keys']['bundle']])) {
            $args[$info['entity keys']['bundle']] = $bundle;
          }

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
      'types' => array_merge($this->objectTypes + $this->interfaceTypes)
    ]);

    return $schema;
  }

  public function entityToGqlQueryArg($entity_type, $bundle = NULL) {
    $fields = $this->getFields($entity_type, $bundle);
    $args = [];
    foreach ($fields as $field => $field_info) {
      if (!($field_info['type'] instanceof ObjectType) && !($field_info['type'] instanceof InterfaceType)) {

        // convert ID to int
        if ($field_info['type'] instanceof IDType) {
          $field_info['type'] = Type::int();
        }

        if (in_array($field, $this->getEntityInfo($entity_type)['schema_fields_sql']['base table'])) {
          $args[$field] = $field_info;
        }
      }
    }
    return $args;
  }

  /**
   * Drupal entity_type to graphql interface, objects
   *
   * @param $entity_type
   * @return array
   */
  public function addEntityGqlDefinition($entity_type) {
    $self = $this;
    $entity_type_info = $this->getEntityInfo($entity_type);
    $isSingleBundle = FALSE;
    $defs = [
      'interface' => NULL,
      'objects' => []
    ];

    if (count($entity_type_info['bundles']) === 1 && key($entity_type_info['bundles']) === $entity_type) {
      $isSingleBundle = TRUE;
    }

    if (!$isSingleBundle) {
      if (!isset($this->interfaceTypes[$entity_type])) {
        $defs['interface'] = $this->addInterfaceType($entity_type, [
          'name' => $entity_type,
          'description' => isset($entity_type_info['description']) ? $entity_type_info['description'] : '',
          'fields' => $this->getFields($entity_type),
          'resolveType' => function ($obj) use($entity_type, &$self) {
            list($id, $rid, $bundle) = entity_extract_ids($entity_type, $obj);
            $objectType = $self->objectTypes[$bundle];
            return $objectType;
          },
          '__entity_bundle' => null,
          '__entity_type' => $entity_type
        ]);
      } else {
        $defs['interface'] = $this->interfaceTypes[$entity_type];
      }
    }

    // entity have single bundle: user, taxonomy_vocabulary, file
    foreach ($entity_type_info['bundles'] as $bundle => $bundle_info) {
      if (!isset($this->objectTypes[$bundle])) {
        $defs['objects'][$bundle] = $this->addObjectType($bundle, [
          'name' => $bundle,
          'description' => '',
          'fields' => $this->getFields($entity_type, $bundle),
          'interfaces' => $isSingleBundle ? [] : [$this->interfaceTypes[$entity_type]],
          '__entity_bundle' => $bundle,
          '__entity_type' => $entity_type
        ]);
      } else {
        $defs['objects'][$bundle] = $this->objectTypes[$bundle];
      }
    }

    if ($isSingleBundle) {
      $defs['interface'] = reset($defs['objects']);
    }

    return $defs;
  }

  /**
   * Add GraphQL interface
   *
   * @param $name
   * @param $info
   * @return mixed
   */
  public function addInterfaceType($name, $info) {
    $this->interfaceTypes[$name] = new InterfaceType($info);
    return $this->interfaceTypes[$name];
  }

  /**
   * Add GraphQL object
   *
   * @param $name
   * @param $info
   * @return mixed
   */
  public function addObjectType($name, $info) {
    $this->objectTypes[$name] = new ObjectType($info);
    return $this->objectTypes[$name];
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
        'description' => isset($info['description']) ? $info['description'] : '',
        'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $property) {
          $wrap = entity_metadata_wrapper($entity_type, $value);
          $items = $wrap->{$property}->value();
          return $items;
        }
      ];
      if (in_array($property, [$entity_keys['id'], $entity_keys['revision']])) {
        $fields[$property]['type'] = Type::id();
      }
    }

    if ($bundle && !empty($properties_info['bundles'][$bundle])) {
      foreach ($properties_info['bundles'][$bundle]['properties'] as $field => $field_info) {
        $fieldType = $this->gqlFieldType($field_info['type'], ['entity_type' => $entity_type, 'bundle' => $bundle, 'property' => $field, 'info' => $field_info]);
        if (!$fieldType) {
          throw new Error("Cannot detect field type of {$field}");
        }
        $short_field = preg_replace('/^field_/', '', $field);
        if (isset($fields[$short_field])) {
          $short_field = $field;
        }

        $fields[$short_field] = [
          'type' => $fieldType,
          'description' => $field_info['description'],
          'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $field) {
            $wrap = entity_metadata_wrapper($entity_type, $value);
            $items = $wrap->{$field}->value();
            return $items;
          }
        ];
      }
    }

    return $fields;
  }

  /**
   * Convert entity metadata 'list<>' -> Type::listOf()
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
      } else {
        throw new Error("Cannot convert {$drupalType} to GraphQL type." . print_r($context, TRUE));
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
   * @throws \GraphQL\Error\Error
   */
  public function drupalToGqlFieldType($drupalType, $context = []) {
    $self = $this;
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
        } else if ($this->getEntityInfo($drupalType)) {
          return function () use ($drupalType, $self) {
            $interface = $self->addEntityGqlDefinition($drupalType)['interface'];
            return $interface;
          };
        }
        throw new Error("Cannot convert {$drupalType} to GraphQL type." . print_r($context, TRUE));
    }
  }

}