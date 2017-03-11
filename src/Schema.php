<?php
namespace Drupal\graphql_api;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Schema as GraphQLSchema;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\UnionType;

class Schema {

  /**
   * GraphQL interface types
   * @var array
   */
  private $interfaceTypes = [];

  /**
   * GraphQL Object types
   * @var array
   */
  private $objectTypes = [];

  /**
   * GraphQL Union types
   * @var array
   */
  private $unionTypes = [];

  /**
   * GraphQL Info from hook
   * @var array
   */
  private $graphqlInfo = [];

  /**
   * Entity type to ignores
   * @todo shouldn't be here
   * @var array
   */
  private $blacklist = ['menu_link', 'wysiwyg_profile'];

  /**
   * Contain all errors while build GraphQL schema
   *
   * @var array
   */
  public $errors = [];


  private $allArgTypes = [];


  public function __construct() {
    $this->graphqlInfo = graphql_api_info();
    foreach ($this->graphqlInfo['types'] as $type => $type_info) {
      if (is_callable($type_info)) {
        $this->objectTypes[$type] = call_user_func($type_info);
      }
      else {
        $this->objectTypes[$type] = $type_info;
      }
    }
  }

  public function addError($message) {
    $this->errors[] = $message;
  }

  /**
   * Get entity info
   *
   * @param null $entity_type
   * @return array|mixed
   */
  public function getEntityInfo($entity_type = NULL) {
    $info = entity_get_info();
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
   * Convert GraphQL query arguments into EntityFieldQuery conditions.
   *
   * @param $args
   * @return array
   */
  public function gqlArgToQueryArg($args) {
    foreach ($args as $arg => $value) {
      // Field API query must provide column in query arguments
      // this code split {field_name}__{column}
      if (preg_match('/[^_]+__[^_]+/', $arg)) {
        $parts = explode('__', $arg);

        // Sometime we need query value in array
        // this code remove "_IN"
        if (preg_match('/(.+)_IN$/', $parts[1], $matches)) {
          unset($args[$arg]);
          $args[$parts[0]] = [$matches[1] => $value];
        } else {
          $args[$parts[0]] = [$parts[1] => $value];
        }

        unset($args[$arg]);
      }

      // Sometime we need query value in array
      // this code remove "_IN"
      if (preg_match('/(.+)_IN$/', $arg, $matches)) {
        unset($args[$arg]);
        $args[$matches[1]] = $value;
      }
    }

    return $args;
  }

  /**
   * Build GraphQL Schema
   *
   * @return \GraphQL\Schema
   */
  public function build() {
    $self = $this;
    $entity_types = $this->getEntityInfo();

    $this->addFieldDefs();

    foreach ($entity_types as $entity_type => $entity_type_info) {
      if (in_array($entity_type, $this->blacklist)) {
        continue;
      }
      if (!empty($this->whitelist) && !in_array($entity_type, $this->whitelist)) {
        continue;
      }
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
        'resolve' => function ($root, $args) use ($type, $self) {
          $op = 'view';

          $args = $self->gqlArgToQueryArg($args);
          $query = graphql_api_entity_get_query($type, $args);
          // Limit, paging
          if (!empty($args['_limit'])) {
            $_skip = !empty($args['_skip']) ? $args['_skip'] : 0;
            $query->range($_skip, $args['_limit']);
          }
          // Sort
          if (!empty($args['_sort'])) {
            $_dir = !empty($args['_direction']) ? $args['_direction'] : 'ASC';
            $query->propertyOrderBy($args['_sort'], $_dir);
          }
          $result = $query->execute();
          if (!empty($result[$type])) {
            $entities = entity_load($type, array_keys($result[$type]));
            $entities = array_filter($entities, function ($entity) use ($op, $type) {
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

          $args = $self->gqlArgToQueryArg($args);

          $query = graphql_api_entity_get_query($type, $args);

          // Limit, paging
          if (!empty($args['_limit'])) {
            $_skip = !empty($args['_skip']) ? $args['_skip'] : 0;
            $query->range($_skip, $args['_limit']);
          }
          // Sort
          if (!empty($args['_sort'])) {
            $_dir = !empty($args['_direction']) ? $args['_direction'] : 'ASC';
            $query->propertyOrderBy($args['_sort'], $_dir);
          }

          $result = $query->execute();
          if (!empty($result[$type])) {
            $entities = entity_load($type, array_keys($result[$type]));
            $entities = array_filter($entities, function ($entity) use ($op, $type) {
              return entity_access($op, $type, $entity);
            });
            return $entities;
          }
          return NULL;
        },
      ];
    }

    drupal_alter('graphql_api_schema', $this);
    drupal_alter('graphql_api_query_info', $queryTypeInfo, $this);
    $queryType = new ObjectType($queryTypeInfo);


    // TODOC
    $mutationType = NULL;
    $schema = new GraphQLSchema([
      'query' => $queryType,
      'mutation' => $mutationType,

      // We need to pass the types that implement interfaces in case the types are only created on demand.
      // This ensures that they are available during query validation phase for interfaces.
      'types' => array_merge($this->objectTypes, $this->interfaceTypes, $this->unionTypes)
    ]);
    return $schema;
  }

  public function addFieldDefs() {
    foreach (field_info_fields() as $field => $field_info) {
      if (!empty($field_info['columns'])) {
        $graphql_fields = [];
        foreach ($field_info['columns'] as $column => $info) {
          $resolve_type = $this->drupalToGqlFieldType($info['type']);
          if (!$resolve_type) {
          }
          $graphql_fields[$column] = [
            'type' => $this->drupalToGqlFieldType($info['type']),
            'description' => ''
          ];
        }
        $objectDef = [
          'name' => $field,
          'description' => '',
          'fields' => $graphql_fields,
          'interfaces' => [],
        ];
        $this->addObjectType($field, $objectDef);

        // field_type have same schema with field, we can reuse definitions and change name
        if (!isset($this->objectTypes[$field_info['type']])) {
          $objectDef['name'] = $field_info['type'];
          $this->addObjectType($field_info['type'], $objectDef);
        }
      }
    }
  }

  public function entityToGqlQueryArg($entity_type, $bundle = NULL) {
    static $args;
    $args = &drupal_static(__CLASS__ . __METHOD__ . $entity_type . $bundle);

    if (empty($args)) {
      $fields = $this->getFields($entity_type, $bundle);
      $paging_args = [
        '_skip' => [
          'name' => '_skip',
          'type' => Type::int(),
        ],
        '_limit' => [
          'name' => '_limit',
          'type' => Type::int(),
        ],
        '_sort' => [
          'name' => '_sort',
          'type' => Type::string(),
        ],
        '_direction' => [
          'name' => '_direction',
          'type' => Type::string(),
          'defaultValue' => 'ASC'
        ]
      ];

      $args = $paging_args;

      foreach ($fields as $field => $field_info) {
        if ($field_info['type'] instanceof ListOfType) {
          if (isset($this->objectTypes[$field])) {
            $field_info['type'] = $this->objectTypes[$field];
          }
          else {
            if (!($field_info['type']->ofType instanceof \Closure) && !($field_info['type']->ofType instanceof InterfaceType)) {
              $args[$field] = [
                'type' => $field_info['type']->ofType,
                'name' => $field,
              ];
              $this->allArgTypes[get_class($field_info['type']->ofType)] = get_class($field_info['type']->ofType);
            }
          }
        }

        if (in_array($field, $this->getEntityInfo($entity_type)['schema_fields_sql']['base table'])) {
          // convert ID to int
          if ($field_info['type'] instanceof IDType) {
            $field_info['type'] = Type::string();
          }

          // ID should be string
          if ($field_info['type'] instanceof ObjectType || $field_info['type'] instanceof InterfaceType) {
            $field_info['type'] = Type::string();
          }

          $args[$field] = $field_info;
          $this->allArgTypes[get_class($field_info['type'])] = get_class($field_info['type']);
        }
        else {
          if ($field_info['type'] instanceof ObjectType || $field_info['type'] instanceof InterfaceType) {
            foreach ($field_info['type']->config['fields'] as $col => $col_info) {
              if (!($col_info['type'] instanceof \Closure)) {
                $args[$field . '__' . $col] = [
                  'name' => $field . '__' . $col,
                  'type' => $col_info['type'],
                ];
              }
              else {
                $this->addError("Type closure cannot be used as args {$field}__{$col}");
              }
            }
          }
        }
      }

      foreach ($args as $arg_name => $arg) {
        if (isset($paging_args[$arg_name])) continue;

        $args["{$arg_name}_IN"] = [
          'name' => "{$arg_name}_IN",
          'type' => Type::listOf($arg['type'])
        ];
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
    static $defs;
    $defs = &drupal_static(__CLASS__ . __METHOD__ . $entity_type);

    if (empty($defs)) {
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

      if (!isset($this->interfaceTypes[$entity_type])) {
        $defs['interface'] = $this->addInterfaceType($entity_type, [
          'name' => $entity_type,
          'description' => isset($entity_type_info['description']) ? $entity_type_info['description'] : '',
          'fields' => function () use($self, $entity_type) {
            return $self->getFields($entity_type);
          },
          'resolveType' => function ($obj) use ($entity_type, &$self) {
            list($id, $rid, $bundle) = entity_extract_ids($entity_type, $obj);
            $objectType = $self->objectTypes[$entity_type . '_' . $bundle];
            return $objectType;
          },
          '__entity_bundle' => NULL,
          '__entity_type' => $entity_type
        ]);
      }
      else {
        $defs['interface'] = $this->interfaceTypes[$entity_type];
      }

      // entity have single bundle: user, taxonomy_vocabulary, file
      foreach ($entity_type_info['bundles'] as $bundle => $bundle_info) {
        $bundle_name = $entity_type . '_' . $bundle;
        if (!isset($this->objectTypes[$bundle_name])) {
          $defs['objects'][$bundle_name] = $this->addObjectType($bundle_name, [
            'name' => $bundle_name,
            'description' => '',
            'fields' => function () use($self, $entity_type, $bundle) {
              return $self->getFields($entity_type);
            },
            'interfaces' => $isSingleBundle ? [] : [$this->interfaceTypes[$entity_type]],
            '__entity_bundle' => $bundle,
            '__entity_type' => $entity_type
          ]);
        }
        else {
          $defs['objects'][$bundle_name] = $this->objectTypes[$bundle_name];
        }
      }
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

  public function getInterfaceType($name) {
    return isset($this->interfaceTypes[$name]) ? $this->interfaceTypes[$name] : FALSE;
  }

  /**
   * Add GraphQL object
   *
   * @param $name
   * @param $info
   * @return mixed
   */
  public function addObjectType($name, $info) {
    if (!isset($this->objectTypes[$name])) {
      $this->objectTypes[$name] = new ObjectType($info);
    }
    return $this->objectTypes[$name];
  }

  public function getObjectType($name) {
    return isset($this->objectTypes[$name]) ? $this->objectTypes[$name] : FALSE;
  }


  public function addUnionType($name, $info) {
    if (!isset($this->unionTypes[$name])) {
      $this->unionTypes[$name] = new UnionType($info);
    }
    return $this->unionTypes[$name];
  }

  public function getUnionType($name) {
    return isset($this->unionTypes[$name]) ? $this->unionTypes[$name] : FALSE;
  }

  /**
   * Get GraphQL fields definition for given entity type / bundle
   *
   * @param $entity_type
   * @param string $bundle
   * @return array
   */
  public function getFields($entity_type, $bundle = '') {
    $self = $this;
    static $fields;
    $fields = &drupal_static(__CLASS__ . __METHOD__ . $entity_type . $bundle);

    if (empty($fields)) {
      $fields = [];
      $properties_info = $this->getPropertyInfo($entity_type);
      if (!$properties_info) {
        return $fields;
      }

      $entity_info = $this->getEntityInfo($entity_type);
      $entity_keys = $entity_info['entity keys'];
      if ($properties_info['properties']) {
        foreach ($properties_info['properties'] as $property => $info) {
          $fieldType = isset($info['type']) ? $this->gqlFieldType($info['type'], [
            'entity_type' => $entity_type,
            'bundle' => NULL,
            'property' => $property,
            'info' => $info
          ]) : Type::string();

          if (!$fieldType) {
            // dump($info);
            // die("Cannot detect fieldType for {$entity_type} {$property}");
            $this->addError("Cannot detect fieldType for {$entity_type} {$property}");
            continue;
          }

          $fields[$property] = [
            'type' => $fieldType,
            'description' => isset($info['description']) ? $info['description'] : '',
            'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $property) {
              if (isset($value->wrapper)) {
                $wrap = $value->wrapper();
              } else {
                $wrap = entity_metadata_wrapper($entity_type, $value);
              }
              $items = $wrap->{$property}->value();
              return $items;
            }
          ];
          if (in_array($property, [
            $entity_keys['id'],
            $entity_keys['revision']
          ])) {
            $fields[$property]['type'] = Type::id();
          }
        }
      }

      if (!empty($properties_info['bundles'])) {
        foreach ($properties_info['bundles'] as $prop_bundle => $bundle_info) {
          foreach ($bundle_info['properties'] as $field => $field_info) {
            // if user filter fields by bundle
            if ($bundle && $bundle != $prop_bundle) {
              continue;
            }
            if ($field_info['type'] === 'a_field_relation_reference') {
              continue;
            }

            $fieldType = $this->gqlFieldType($field_info['type'], [
              'entity_type' => $entity_type,
              'bundle' => $bundle,
              'property' => $field,
              'info' => $field_info
            ]);

            if (!$fieldType) {
              // dump($fieldType, $field_info);
              // die("Cannot detect field type of {$field}");
              $this->addError("Cannot convert Drupal field type '{$field}' -> GrahpQL field type.");
              continue;
            }

            if (!is_callable($fieldType) && isset($this->interfaceTypes[$fieldType->name])) {
              $field_info = field_info_field($field);
              switch ($field_info['type']) {
                case 'field_collection':
                  break;
                case 'taxonomy_term_reference':
                  $voca = $field_info['settings']['allowed_values'][0]['vocabulary'];
                  if (isset($this->objectTypes['taxonomy_term_' . $voca])) {
                    $fieldType = $this->objectTypes['taxonomy_term_' . $voca];
                  } else {
                    $this->addError("Taxonomy vocabulary {$voca} doesn't exists when trying to detect field {$field}!");
                  }
                  break;
                case 'entityreference':
                  $target_type = $field_info['settings']['target_type'];
                  $target_bundles = array();
                  if (is_array($field_info['settings']['handler_settings']['target_bundles'])) {
                    $target_bundles = array_values($field_info['settings']['handler_settings']['target_bundles']);
                  }
                  if (empty($target_bundles)) {
                    $target_bundles = array_keys($self->getEntityInfo($target_type)['bundles']);
                  }
                  if (count($target_bundles) === 1 && isset($this->objectTypes[$target_type . '_' . $target_bundles[0]])) {
                    $fieldType = $this->objectTypes[$target_type . '_' . $target_bundles[0]];
                  } else {
                    $fieldType = $self->addUnionType($field, [
                      'name' => $field . '_union',
                      'types' => array_map(function ($target_bundle) use ($self, $target_type) {
                        return $this->objectTypes[$target_type . '_' . $target_bundle];
                      }, $target_bundles),
                      'resolveType' => function ($value) {
                        dump('resolve type of union', $value);
                        die();
                      }
                    ]);
                  }
                  break;
                default:
                  dump($field, $fieldType, $field_info);
                  die("Try to resolve interface to object in field level failed. {$field} {$entity_type} {$bundle}");
              }
            }

            $fields[$field] = [
              'type' => $fieldType,
              'description' => isset($field_info['description']) ? $field_info['description'] : '',
              'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $field, $fieldType) {
                $wrap = entity_metadata_wrapper($entity_type, $value);
                if ($wrap->__isset($field)) {
                  $items = $wrap->{$field}->value();

                  // field file, image
                  if (in_array($fieldType->name, ['field_item_image', 'field_item_file']) && !empty($items['fid'])) {
                    $items['file'] = file_load($items['fid']);
                  }
                  // field file, image multiple
                  if ($fieldType instanceof ListOfType && in_array($fieldType->ofType->name, ['field_item_image', 'field_item_file'])) {
                    foreach ($items as $index => $item) {
                      $items[$index]['file'] = file_load($item['fid']);
                    }
                  }

                  return $items;
                }
                return NULL;
              }
            ];
          }
        }
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
      }
      else {
        $this->addError("Cannot convert {$drupalType} to GraphQL type." . print_r($context, TRUE));
        return FALSE;
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
    $type = FALSE;

    // check custom types
    switch ($drupalType) {
      case 'string':
      case 'varchar':
      case 'text':
        $type = Type::string();
        break;
      case 'int':
      case 'integer':
        $type = Type::int();
        break;
      case 'float':
      case 'decimal':
        $type = Type::float();
        break;
      case 'boolean':
        $type = Type::boolean();
        break;
      case 'date':
        $type = Type::int();
        break;
      case 'uri':
        return Type::string();
      case 'token':
        $type = Type::string();
        break;
      case 'datetime':
        // @fixme datetime should have object defs
        $type = Type::string();
        break;
      case 'struct':
        // @fixme struct should have object defs
        $type = Type::string();
        break;
      case 'array':
        $type = ListOfType::string();
        break;
      default:
        if (isset($this->interfaceTypes[$drupalType])) {
          $type = $this->interfaceTypes[$drupalType];
        }
        else {
          if (isset($this->objectTypes[$drupalType])) {
            $type = $this->objectTypes[$drupalType];
          }
          else {
            if (isset($this->getEntityInfo()[$drupalType])) {
              $result = $self->addEntityGqlDefinition($drupalType);
              if (isset($result['interface'])) {
                $type = $result['interface'];
              }
              else {
                if (isset($result['object'])) {
                  $type = $result['object'];
                }
              }
            }
            else {
              if (!empty($context) && !empty($context['property']) && preg_match('/^field_|body/', $context['property'])) {
                $type = !empty($self->objectTypes[$drupalType]) ? $self->objectTypes[$drupalType] : FALSE;
              }
              else {
                if ($context['entity_type'] === 'field_collection_item' && $drupalType === 'entity') {
                  $type = Type::int();
                }
                else {
                  if ($context['entity_type'] === 'relation' && $drupalType === 'entity') {
                    $type = Type::int();
                  }
                }
              }
            }
          }
        }
    }

    if (!$type) {
      // dump($context, debug_backtrace());
      // die("Cannot convert {$drupalType} to GraphQL type.");
      $this->addError("Cannot convert Drupal property type '{$drupalType}' -> GraphQL type. Please register this type with hook_graphql_api_info()");
    }

    return $type;
  }

}
