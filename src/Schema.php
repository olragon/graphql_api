<?php
namespace Drupal\graphql_api;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\SchemaConfig;
use GraphQL\Type\Schema as GraphQLSchema;

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
  public $blacklist = ['menu_link', 'wysiwyg_profile'];

  /**
   * Contain all errors while build GraphQL schema
   *
   * @var array
   */
  private $errors = [];


  private $allArgTypes = [];

  private $metrics = [];

  private $stats = [];

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

  public function addError($message, $includeBacktrace = false) {
    $this->errors[] = $message;
  }

  public function watchdog() {
    if (!empty($this->errors)) {
      foreach($this->errors as $error) {
        watchdog('GraphQL', $error);
      }
    }
  }

  /**
   * Collection benchmark metric
   *
   * @param      $metric
   * @param null $time
   */
  private function collectTimeMetric($metric, $time = NULL) {
    if (!$time) {
      $time = microtime(TRUE);
    }

    if (!empty($this->metrics[$metric])) {
      $this->metrics[$metric] = $time - $this->metrics[$metric];
    } else {
      $this->metrics[$metric] = $time;
    }
  }

  /**
   * Get benchmark metrics
   *
   * @return array
   */
  public function getMetrics() {
    return $this->metrics;
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
      $arg = preg_replace('/_IN$/', '', $arg);
      // Field API query must provide column in query arguments
      // this code split {field_name}__{column}
      if (preg_match('/[^_]+__[^_]+/', $arg)) {
        $parts = explode('__', $arg);
        $args[$parts[0]] = [$parts[1] => $value];
        unset($args[$arg]);
      }
      if ($value === NULL) {
        unset($args[$arg]);
      }
    }

    return $args;
  }

  /**
   * Get maps of all entity type and bundles
   */
  public function drupalEntityTypesAndBundles() {
    $maps = [];
    foreach ($this->getEntityInfo() as $entity_type => $entity_info) {
      $maps[$entity_type] = [
        'name' => $entity_type,
        'entity_type' => $entity_type,
        'bundle' => '',
      ];
      $maps[$entity_type.'_input_type'] = [
        'name' => $entity_type,
        'entity_type' => $entity_type,
        'bundle' => '',
      ];
      foreach (array_keys($entity_info['bundles']) as $bundle) {
        $maps[$entity_type . '_' . $bundle] = [
          'name' => $entity_type . '_' . $bundle,
          'entity_type' => $entity_type,
          'bundle' => $bundle,
        ];
        $maps[$entity_type. '_'. $bundle . '_input_type'] = [
          'name' => $entity_type,
          'entity_type' => $entity_type,
          'bundle' => '',
        ];
      }
    }
    return $maps;
  }

  public function buildType($typeName, $is_mutation) {
    $introspec_method = preg_replace('/^__/', '', $typeName);
    $introspec_method = '_' . lcfirst($introspec_method);

    $classNames = [
      '\GraphQL\Type\Definition\\' . $typeName
    ];

    foreach ($classNames as $className) {
      if (method_exists('\GraphQL\Type\Introspection', $introspec_method)) {
        return call_user_func("\GraphQL\Type\Introspection::{$introspec_method}");
      }
      if (class_exists($className)) {
        return new $className();
      }
    }

    // build whole entity type
    foreach ($this->getEntityInfo() as $entity_type => $entity_type_info) {
      if (in_array($entity_type, $this->blacklist)) {
        continue;
      }
      // define interface
      $this->addEntityGqlDefinition($entity_type);
    }

    $maps = $this->drupalEntityTypesAndBundles();

    if ($is_mutation) {
      $this->getMutation();
    }

    // get object / interface
    if (isset($maps[$typeName]) || $this->objectTypes[$typeName]) {
      $objectType = $this->getObjectType($typeName);
      $interfaceType = $this->getInterfaceType($typeName);
      return $objectType ? $objectType : $interfaceType;
    }

    die('to be implemented :: buildType :: ' . $typeName);
    return null;
  }

  /**
   * Build GraphQL Schema
   *
   * @return \GraphQL\Type\Schema
   */
  public function build($options = [], $is_mutation) {
    $schema = &drupal_static(__METHOD__);

    if (!$schema) {
      $self = $this;
      $entity_types = $this->getEntityInfo();

      $this->addFieldDefs();

      $this->collectTimeMetric('addEntityGqlDefinition');
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
      $this->collectTimeMetric('addEntityGqlDefinition');

      $queryTypeInfo = ['name' => 'Query'];
      $this->collectTimeMetric('buildInterfaceTypes');
      foreach ($this->interfaceTypes as $type => $interface) {
        if (!isset($interface->config['__entity_type'])) {
          continue;
        }

        // load single entity
        $queryTypeInfo['fields'][$type.'_single'] = [
          'type' => $interface,
          'args' => [
            'id' => [
              'name' => 'id',
              'type' => Type::string(),
              'required' => true
            ]
          ],
          'resolve' => function ($root, $args) use ($type, $self) {
            $op = 'view';
            $id = $args['id'];
            $entity = entity_load_single($type, $id);
            return entity_access($op, $type, $entity) ? $entity : null;
          }
        ];

        // load multiple entities
        $queryTypeInfo['fields'][$type] = [
          'type' => Type::listOf($interface),
          'args' => $this->entityToGqlQueryArg($interface->config['__entity_type']),
          'resolve' => function ($root, $args) use ($type, $self) {
            $op = 'view';

            $args = $self->gqlArgToQueryArg($args);
            $query = graphql_api_entity_get_query($type, $args);

            if (!empty($args['_where'])) {
              $self->gqlWhereToDBSelect($query, $args['_where'], $type);
            }

            if (empty($args['_limit'])) {
              $args['_limit'] = variable_get('graphql_api_limit', 50);
            }

            // Limit, paging
            if (!empty($args['_limit'])) {
              $_skip = !empty($args['_skip']) ? $args['_skip'] : 0;
              $query->range($_skip, $args['_limit']);
            }
            // Sort
            if (!empty($args['_sort'])) {
              $_dir = !empty($args['_direction']) ? $args['_direction'] : 'ASC';
              $query->orderBy($args['_sort'], $_dir);
            }
            // group by
            if (!empty($args['_groupby'])) {
              $groupbies = explode(',', $args['_groupby']);
              $groupbies = array_map(function ($groupby) {
                return 'b.'.trim($groupby);
              }, $groupbies);
              foreach ($groupbies as $groupby) {
                $query->groupBy($groupby);
              }
            }

            $self->debugQuery($args, $query);

            $ids = $query->execute()->fetchCol();

            if ($ids) {
              $entities = entity_load($type, $ids);
              $entities = array_filter($entities, function ($entity) use ($op, $type) {
                return entity_access($op, $type, $entity);
              });
              return $entities;
            }
            return [];
          },
        ];

        $queryTypeInfo['fields']['aggregations_' . $type] = [
          'name' => 'aggregations_' . $type,
          'args' => $this->entityToGqlQueryArg($interface->config['__entity_type']),
          'type' => $this->getAggregationsField($type),
          'resolve' => function ($root, $args) use ($type, $self) {
            $args = $self->gqlArgToQueryArg($args);
            $query = graphql_api_entity_get_query($type, $args);
            if (!empty($args['_where'])) {
              $self->gqlWhereToDBSelect($query, $args['_where'], $type);
            }
            return $query;
          }
        ];
      }
      $this->collectTimeMetric('buildInterfaceTypes');

      $this->collectTimeMetric('buildObjectTypes');
      foreach ($this->objectTypes as $type => $object) {
        if (!isset($object->config['__entity_type']) || !isset($object->config['__entity_bundle'])) {
          continue;
        }

        // load single entity
        $queryTypeInfo['fields'][$type.'_single'] = [
          'type' => $object,
          'args' => ['id' => ['type' => Type::string()]],
          'resolve' => function ($root, $args) use ($object, $self) {
            $type = $object->config['__entity_type'];
            $bundle = $object->config['__entity_bundle'];

            $op = 'view';
            $id = $args['id'];
            $entity = entity_load_single($type, $id);
            return entity_access($op, $type, $entity) ? $entity : null;
          }
        ];

        // load multiple entities
        $queryTypeInfo['fields'][$type] = [
          'type' => Type::listOf($object),
          'args' => $this->entityToGqlQueryArg($object->config['__entity_type'], $object->config['__entity_bundle']),
          'resolve' => function ($root, $args, $context, ResolveInfo $resolveInfo) use ($object, $self) {
            $type = $object->config['__entity_type'];
            $bundle = $object->config['__entity_bundle'];
            $info = $self->getEntityInfo($type);
            $fields = array_keys($resolveInfo->getFieldSelection());

            if (isset($info['entity keys']['bundle']) && !isset($args[$info['entity keys']['bundle']])) {
              $args[$info['entity keys']['bundle']] = $bundle;
            }

            $op = 'view';

            $args = $self->gqlArgToQueryArg($args);

            $query = graphql_api_entity_get_query($type, $args, $fields);

            if (!empty($args['_where'])) {
              $self->gqlWhereToDBSelect($query, $args['_where'], $type);
            }

            if (empty($args['_limit'])) {
              $args['_limit'] = variable_get('graphql_api_limit', 50);
            }

            // Limit, paging
            if (!empty($args['_limit'])) {
              $_skip = !empty($args['_skip']) ? $args['_skip'] : 0;
              $query->range($_skip, $args['_limit']);
            }

            // Sort
            if (!empty($args['_sort'])) {
              $_dir = !empty($args['_direction']) ? $args['_direction'] : 'ASC';
              $query->orderBy($args['_sort'], $_dir);
            }

            // group by
            if (!empty($args['_groupby'])) {
              $groupbies = explode(',', $args['_groupby']);
              $groupbies = array_map(function ($groupby) {
                return 'b.'.trim($groupby);
              }, $groupbies);
              foreach ($groupbies as $groupby) {
                $query->groupBy($groupby);
              }
            }

            if (!empty($args['_distinct'])) {
              $result = $query->execute()->fetchAll();
              return $result;
            } else {
              $ids = $query->execute()->fetchCol();
            }

            if ($ids) {
              $entities = array_map(function ($id) use ($type) {
                return entity_metadata_wrapper($type, $id);
              }, $ids);
              $entities = array_filter($entities, function ($entity) use ($op, $type) {
                return entity_access($op, $type, $entity);
              });
              return $entities;
            }

            return NULL;
          },
        ];

        $entity_type = $object->config['__entity_type'];
        $queryTypeInfo['fields']['aggregations_' . $type] = [
          'name' => 'aggregations_' . $type,
          'args' => $this->entityToGqlQueryArg($object->config['__entity_type']),
          'type' => $this->getAggregationsField($object->config['__entity_type']),
          'resolve' => function ($root, $args) use ($entity_type, $self) {
            $args = $self->gqlArgToQueryArg($args);
            $query = graphql_api_entity_get_query($entity_type, $args);

            if (!empty($args['_where'])) {
              $self->gqlWhereToDBSelect($query, $args['_where'], $entity_type);
            }

            // Sort
            if (!empty($args['_sort'])) {
              $_dir = !empty($args['_direction']) ? $args['_direction'] : 'ASC';
              $query->orderBy($args['_sort'], $_dir);
            }

            return $query;
          }
        ];
      }
      $this->collectTimeMetric('buildObjectTypes');

      $this->collectTimeMetric('drupalAlter');
      drupal_alter('graphql_api_schema', $this);
      drupal_alter('graphql_api_query_info', $queryTypeInfo, $this);
      $this->collectTimeMetric('drupalAlter');
      $queryType = new ObjectType($queryTypeInfo);

      $this->collectTimeMetric('GraphQLSchema');
      $config = SchemaConfig::create($options)
                            ->setQuery($queryType)
                            ->setTypes(array_merge($this->objectTypes, $this->interfaceTypes, $this->unionTypes));
      if ($is_mutation) {
        $this->collectTimeMetric('getMutation');
        $mutationType = $this->getMutation();
        $config->setMutation($mutationType);
        $this->collectTimeMetric('getMutation');
      }
      $schema = new GraphQLSchema($config);
      $this->collectTimeMetric('GraphQLSchema');
    }


    return $schema;
  }

  public function getMutation() {
    $self = $this;
    $mutationType = &drupal_static(__METHOD__);
    $mutateTypeInfo = ['name' => 'Mutation'];

    foreach ($this->interfaceTypes as $type => $interface) {
      if (!isset($interface->config['__entity_type'])) {
        continue;
      }
      $mutateTypeInfo['fields'][$type . '_save'] = [
        'type' => $interface,
        'args' => [
          'entity' => [
            'name' => 'entity',
            'type' => $this->addInputObjectType($type . '_input_type', [
              'name' => $type . '_input_type',
              'description' => '',
              'fields' => $this->getInputFields($interface->config['__entity_type'])
            ])
          ]
        ],
        'resolve' => function ($root, $args) use ($interface, $self) {
          $op = 'create';
          $entity_type = $interface->config['__entity_type'];
          $entity_info = entity_get_info($entity_type);
          $bundle_key = $entity_info['entity keys']['bundle'];
          $id_key = $entity_info['entity keys']['id'];
          $values = $args['entity'];
          if (empty($values[$bundle_key]) && count($entity_info['bundles']) == 1) {
            $bundles = array_keys($entity_info['bundles']);
            $bundle = reset($bundles);
            $values[$bundle_key] = $bundle;
          }
          $entity = entity_create($entity_type, $values);
          if (!empty($entity->{$id_key})) {
            $op = 'update';
          }
          if (!entity_access($op, $entity_type, $entity)) {
            return drupal_access_denied();
          }
          if (!empty($entity->{$id_key})) {
            $entity = entity_load_single($entity_type, $entity->{$id_key});
            $entity_wrap = entity_metadata_wrapper($entity_type, $entity);

            if ($entity_type === 'user' && !empty($values['password'])) {
              require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
              user_save($entity, ['pass' => $values['password']]);
              unset($values['password']);
            }

            foreach ($values as $prop => $val) {
              if ($prop === $id_key) continue;
              $entity_wrap->{$prop} = $val;
            }
          }
          entity_save($entity_type, $entity);
          return $entity;
        }
      ];


    }

    foreach ($this->objectTypes as $type => $object) {
      if (!isset($object->config['__entity_type']) || !isset($object->config['__entity_bundle'])) {
        continue;
      }

      $mutateTypeInfo['fields'][$type . '_delete'] = [
        'type' => $this->addObjectType('entity_delete_result', [
          'name' => 'entity_delete_result',
          'fields' => [
            'id' => [
              'name' => 'id',
              'type' => Type::id(),
            ],
            'result' => [
              'name' => 'result',
              'type' => Type::boolean()
            ]
          ]
        ]),
        'args' => [
          'id' => [
            'name' => 'id',
            'type' => Type::id(),
            'required' => true
          ]
        ],
        'resolve' => function ($root, $args) use ($object) {
          $entity_type = $object->config['__entity_type'];
          $result = entity_delete($entity_type, $args['id']);

          $entity = entity_load($entity_type, $args['id']);
          if (!entity_access('delete', $entity_type, $entity)) {
            return drupal_access_denied();
          }

          return (object) [ 'id' => $args['id'], 'result' => $result !== false ];
        }
      ];

      $mutateTypeInfo['fields'][$type . '_save'] = [
        'type' => $object,
        'args' => [
          'entity' => [
            'name' => 'entity',
            'type' => $this->addInputObjectType($type . '_input_type', [
              'name' => $type . '_input_type',
              'description' => '',
              'fields' => $this->getInputFields($object->config['__entity_type'], $object->config['__entity_bundle'])
            ])
          ]
        ],
        'resolve' => function ($root, $args) use ($object, $self) {
          $op = 'create';
          $entity_type = $object->config['__entity_type'];
          $bundle = $object->config['__entity_bundle'];
          $entity_info = entity_get_info($entity_type);
          $bundle_key = $entity_info['entity keys']['bundle'];
          $id_key = $entity_info['entity keys']['id'];
          $values = $args['entity'];
          if (!empty($bundle_key)) {
            $values[$bundle_key] = $bundle;
          }

          $entity = entity_create($entity_type, $values);
          $entity_wrap = entity_metadata_wrapper($entity_type, $entity);

          if (!empty($entity->{$id_key})) {
            $op = 'update';
            unset($entity->is_new);
          }

          if ($entity_type === 'user') {
            if (!empty($values['password'])) {
              require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');
              user_save($entity, ['pass' => $values['password']]);
              unset($values['password']);
            }
          }

          foreach ($values as $prop => $val) {
            if ($prop === $id_key) continue;
            try {
              $entity_wrap->{$prop} = $val;
            } catch (\Exception $e) {
              $this->addError($e->getMessage(), true);
            }
          }

          // check permissions
          if (!entity_access($op, $entity_type, $entity)) {
            return drupal_access_denied();
          }

          try {
            entity_save($entity_type, $entity);
          } catch (\Exception $e) {
            $this->addError($e->getMessage(), true);
          }

          $this->watchdog();

          return $entity;
        }
      ];
    }

    drupal_alter('graphql_api_mutate_info', $mutateTypeInfo, $this);

    $mutationType = $this->addObjectType('Mutation', $mutateTypeInfo);
    return $mutationType;
  }

  public function addFieldDefs() {
    $this->collectTimeMetric(__METHOD__);
    foreach (field_info_fields() as $field => $field_info) {
      $field_properties = [];
      if (!empty($field_info['columns'])) {
        $field_properties = $field_info['columns'];
      }
      if (!empty($field_info['property info'])) {
        $field_properties = $field_info['property info'];
      }

      if (!empty($field_properties)) {
        $graphql_fields = [];
        foreach ($field_properties as $column => $info) {
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
    $this->collectTimeMetric(__METHOD__);
  }

  /**
   * Apply GraphQL where object to db_select()
   *
   * @param \SelectQueryInterface|\DatabaseCondition $select
   * @param                       $where
   * @param                       $entity_type
   */
  public function gqlWhereToDBSelect(&$select, $where, $entity_type, &$_rootQuery = NULL, &$_loop = 0) {
    $_loop++;

    $info = entity_get_info($entity_type);
    $props = entity_get_property_info($entity_type);
    $id_key = $info['entity keys']['id'];
    $all_fields = [];
    // get all fields attached to this entity type
    if (!empty($props['bundles'])) {
      foreach ($props['bundles'] as $bundle => $bundle_info) {
        foreach ($bundle_info['properties'] as $field => $field_info) {
          $all_fields[] = $field;
        }
      }
    }

    $operators = $this->whereOperators();

    foreach ($where as $property => $conditions) {
      if (empty($conditions)) continue;
      if ($property === 'AND') {
        $db_and = db_and();
        $this->gqlWhereToDBSelect($db_and, $conditions, $entity_type, $select, $_loop);
        $select->condition($db_and);
        continue;
      }
      else if ($property === 'OR') {
        $db_or = db_or();
        $this->gqlWhereToDBSelect($db_or, $conditions, $entity_type, $select, $_loop);
        $select->condition($db_or);
        continue;
      }
      else {
        $_f = 0;
        foreach ($conditions as $gqlOperator => $value) {
          if (isset($operators[$gqlOperator])) {
            $def = $operators[$gqlOperator];
            if ($gqlOperator === 'isNull' && $value) {
              $select->isNull($property);
            }
            else if ($gqlOperator === 'isNotNull' && $value) {
              $select->isNotNull($property);
            }
            else {
              if (in_array($property, $info['schema_fields_sql']['base table'])) {
                $select->condition("b.{$property}", $value, $def['operator']);
              }
              else if (in_array($property, $all_fields)) {
                $f_alias = $property;
                if ($_rootQuery) {
                  $unique_alias = $_rootQuery->join('field_data_' . $property, $f_alias, "{$f_alias}.entity_type='{$entity_type}' AND {$f_alias}.entity_id=b.{$id_key} AND {$f_alias}.deleted=0");
                } else {
                  $unique_alias = $select->join('field_data_' . $property, $f_alias, "{$f_alias}.entity_type='{$entity_type}' AND {$f_alias}.entity_id=b.{$id_key} AND {$f_alias}.deleted=0");
                }
                $prop_info = field_info_field($property);
                $columns = array_keys($prop_info['columns']);
                $column = reset($columns);
                $select->condition("{$unique_alias}.{$property}_{$column}", $value, $def['operator']);
                $_f++;
              }
            }
          }
        }
      }
    }
  }

  public function whereOperators() {
    $primitive_types = [Type::int(), Type::string(), Type::id(), Type::boolean(), Type::float()];
    $number_types = [Type::int(), Type::float(), Type::id()];
    $list_of_primitive_types = array_map(function ($primitive_type) {
      return Type::listOf($primitive_type);
    }, $primitive_types);
    $operators = [
      'eq' => [
        'accepts' => $primitive_types,
        'type' => Type::string(),
        'description' => 'Equal to. This takes a higher precedence than the other operators.',
        'operator' => '='
      ],
      'gt' => [
        'accepts' => $number_types,
        'type' => Type::string(),
        'description' => 'Greater than.',
        'operator' => '>'
      ],
      'gte' => [
        'accepts' => $number_types,
        'type' => Type::string(),
        'description' => 'Greater than or equal to.',
        'operator' => '>='
      ],
      'lt' => [
        'accepts' => $number_types,
        'type' => Type::string(),
        'description' => 'Less than.',
        'operator' => '<'
      ],
      'lte' => [
        'accepts' => $number_types,
        'type' => Type::string(),
        'description' => 'Less than or equal to.',
        'operator' => '<='
      ],
      'ne' => [
        'accepts' => $primitive_types,
        'type' => Type::string(),
        'description' => 'Not equal to.',
        'operator' => '<>'
      ],
      'between' => [
        'accepts' => $number_types,
        'type' => Type::listOf(Type::string()),
        'description' => 'A two element tuple describing a range of values.',
        'operator' => 'BETWEEN'
      ],
      'notBetween' => [
        'accepts' => $number_types,
        'type' => Type::listOf(Type::string()),
        'description' => 'A two element tuple describing an excluded range of values.',
        'operator' => 'NOT BETWEEN'
      ],
      'in' => [
        'accepts' => $primitive_types,
        'type' => Type::listOf(Type::string()),
        'description' => 'A list of values to include.',
        'operator' => 'IN'
      ],
      'notIn' => [
        'accepts' => $primitive_types,
        'type' => Type::listOf(Type::string()),
        'description' => 'A list of values to exclude.',
        'operator' => 'NOT IN'
      ],
      'like' => [
        'accepts' => [Type::string()],
        'type' => Type::string(),
        'description' => 'A pattern to match for likeness.',
        'operator' => 'LIKE'
      ],
      'notLike' => [
        'accepts' => [Type::string()],
        'type' => Type::string(),
        'description' => 'A pattern to match for likeness and exclude.',
        'operator' => 'NOT LIKE'
      ],
      'isNull' => [
        'accepts' => $primitive_types,
        'type' => Type::boolean(),
        'description' => 'Filters for null values. This takes precedence after eq but before all other fields.',
        'operator' => 'IS NULL'
      ],
      'isNotNull' => [
        'accepts' => $primitive_types,
        'type' => Type::boolean(),
        'description' => 'Filters for not null values. This takes precedence after eq but before all other fields.',
        'operator' => 'IS NOT NULL'
      ],
    ];
    return $operators;
  }

  public function getWhereArgsOperator($type) {
    $object_key = '_where_arg_' . $type->name;
    if (isset($this->objectTypes[$object_key])) {
      return $this->objectTypes[$object_key];
    }
    $fields = [];
    if (!empty($type->config['__entity_type'])) {
      foreach ($this->whereOperators() as $operator => $stuff) {
        if (in_array(Type::int(), $stuff['accepts'])) {
          $fields[$operator] = [
            'name' => $operator,
            'type' => $stuff['type']
          ];
        }
      }
    }
    else if ($type instanceof ListOfType) {
      if (!empty($type->ofType->config['__entity_type'])) {
        // get all available operators for type int
        foreach ($this->whereOperators() as $operator => $stuff) {
          if (in_array(Type::int(), $stuff['accepts'])) {
            $fields[$operator] = [
              'name' => $operator,
              'type' => $stuff['type']
            ];
          }
        }
      } else {
        foreach ($this->whereOperators() as $operator => $stuff) {
          if (in_array($type->ofType, $stuff['accepts'])) {
            $fields[$operator] = [
              'name' => $operator,
              'type' => $stuff['type']
            ];
          }
        }
      }
    }
    else if ($type instanceof ObjectType || $type instanceof InterfaceType) {
      // field_item_sku_option
      if (!empty($type->config['__entity_type'])) {
        foreach ($this->whereOperators() as $operator => $stuff) {
          if (in_array(Type::int(), $stuff['accepts'])) {
            $fields[$operator] = [
              'name' => $operator,
              'type' => $stuff['type']
            ];
          }
        }
      }
    }
    else if ($type instanceof UnionType) {
      // field_packing_related_item_union
      foreach ($this->whereOperators() as $operator => $stuff) {
        if (in_array(Type::int(), $stuff['accepts'])) {
          $fields[$operator] = [
            'name' => $operator,
            'type' => $stuff['type']
          ];
        }
      }
    }
    else {
      foreach ($this->whereOperators() as $operator => $stuff) {
        if (in_array($type, $stuff['accepts'])) {
          $fields[$operator] = [
            'name' => $operator,
            'type' => $stuff['type']
          ];
        }
      }
    }

    if (empty($fields)) {
      return FALSE;
    }

    return $this->addInputObjectType($object_key, [
      'name' => $object_key,
      'description' => 'Where args of type ' . get_class($type),
      'fields' => $fields,
    ]);
  }

  /**
   * Get where object of given entity type
   *
   * @param $entity_type
   *
   * @return mixed
   */
  public function getWhereObject($entity_type) {
    $that = $this;
    return $this->addInputObjectType($entity_type . '_where', [
      'name' => $entity_type . '_where',
      'description' => 'Query filter',
      'fields' => function () use ($entity_type, $that) {
        $fields = [];
        $entity_object_type = $this->getInterfaceType($entity_type);
        $properties_info = $this->getPropertyInfo($entity_type);

        foreach ($properties_info['properties'] as $property => $property_info) {
          $type = $this->getWhereArgsOperator($entity_object_type->getFields()[$property]->getType());
          if ($type) {
            $fields[$property] = [
              'type' => $type
            ];
          }
        }

        if (!empty($properties_info['bundles'])) {
          foreach ($properties_info['bundles'] as $bundle => $properties) {
            foreach ($properties['properties'] as $field => $field_info) {
              $all_fields = $that->getFields($entity_type, $bundle);
              if (!isset($all_fields[$field])) continue;

              if (($all_fields[$field]['type'] instanceof ObjectType
                  || $all_fields[$field]['type'] instanceof InterfaceType)
                && empty($all_fields[$field]['type']->config['__entity_type'])) {

                foreach ($all_fields[$field]['type']->getFields() as $_field => $_field_info) {
                  $type = $that->getWhereArgsOperator($_field_info->getType());
                  if ($type) {
                    $fields[$_field] = [
                      'type' => $type
                    ];
                  }
                }

              } else {
                $type = $that->getWhereArgsOperator($all_fields[$field]['type']);
                if ($type) {
                  $fields[$field] = [
                    'type' => $type
                  ];
                }
              }
            }
          }
        }

        $fields['AND'] = $that->addInputObjectType($entity_type . '_where_AND', [
          'name' => $entity_type . '_where_AND',
          'fields' => $fields
        ]);
        $fields['OR'] = $that->addInputObjectType($entity_type . '_where_OR', [
          'name' => $entity_type . '_where_OR',
          'fields' => ['AND' => $fields['AND']] + $fields
        ]);
        return $fields;
      },
      'interfaces' => []
    ]);
  }

  public function getAggregationsField($entity_type) {
    static $aggObject;
    $aggObject = &drupal_static(__CLASS__ . __METHOD__ . $entity_type);

    if (!$aggObject) {
      $fields = [
        'count' => [
          'name' => 'count',
          'type' => Type::int(),
          'resolve' => function ($query) use ($entity_type) {
            $q = clone $query;
            return $q->execute()->rowCount();
          }
        ],
        'sum' => [
          'name' => 'sum',
          'type' => Type::float(),
          'resolve' => function ($query, $args, $context, ResolveInfo $info) use ($entity_type) {
            return $query;
          }
        ],
        'avg' => [
          'name' => 'avg',
          'type' => '',
          'resolve' => function ($query, $args, $context, ResolveInfo $info) use ($entity_type) {
            return $query;
          }
        ],
        'max' => [
          'name' => 'max',
          'type' => '',
          'resolve' => function ($query, $args, $context, ResolveInfo $info) use ($entity_type) {
            return $query;
          }
        ],
        'min' => [
          'name' => 'min',
          'type' => '',
          'resolve' => function ($query, $args, $context, ResolveInfo $info) use ($entity_type) {
            return $query;
          }
        ]
      ];
      $entity_object_type = $this->getInterfaceType($entity_type);
      $property_info = $this->getPropertyInfo($entity_type);

      $agg_fields = [];
      foreach (['sum', 'avg', 'max', 'min'] as $agg) {
        if (empty($property_info['properties'])) {
          unset($fields[$agg]);
          continue;
        }

        foreach ($property_info['properties'] as $property => $property_info) {
          $type = $entity_object_type->getFields()[$property]->getType();
          if ($type && in_array($type, [Type::int(), Type::float()])) {
            $agg_fields[$property] = [
              'name' => $property,
              'type' => Type::float(),
              'resolve' => function ($query) use ($agg, $property) {
                $q = clone $query;
                switch ($agg) {
                  case 'sum':
                    $alias = $q->addExpression('SUM('. $property .')', '_v');
                    break;
                  case 'avg':
                    $alias = $q->addExpression('AVG('. $property .')', '_v');
                    break;
                  case 'max':
                    $alias = $q->addExpression('MAX('. $property .')', '_v');
                    break;
                  case 'min':
                    $alias = $q->addExpression('MIN('. $property .')', '_v');
                    break;
                }
                $v = $q->execute()->fetchField(1);
                return $v;
              }
            ];
          }
        }

        if (!empty($agg_fields)) {
          $fields[$agg]['type'] = $this->addObjectType($entity_type . '_aggregations_' . $agg, [
            'name' => $entity_type . '_aggregations_' . $agg,
            'fields' => $agg_fields
          ]);
        }
      }

      $aggObject = $this->addObjectType($entity_type . '_aggregations', [
        'name' => $entity_type . '_aggregations',
        'description' => 'Aggregations of ' . $entity_type,
        'fields' => $fields,
        'interfaces' => []
      ]);
    }

    return $aggObject;
  }

  /**
   * Get query arguments of given entity type
   *
   * @param      $entity_type
   * @param null $bundle
   * @param bool $is_mutation
   *
   * @return array|mixed
   */
  public function entityToGqlQueryArg($entity_type, $bundle = NULL, $is_mutation = FALSE) {
    static $args;
    $args = &drupal_static(__CLASS__ . __METHOD__ . $entity_type);

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
        ],
        '_where' => [
          'name' => '_where',
          'type' => $this->getWhereObject($entity_type),
        ],
        '_distinct' => [
          'name' => '_distinct',
          'type' => Type::boolean(),
        ],
        '_groupby' => [
          'name' => '_groupby',
          'type' => Type::string(),
        ]
      ];

      if ($is_mutation) {
        $paging_args = [];
      }

      $args = $paging_args;

      foreach ($fields as $field => $field_info) {
        if ($field_info['type']->name === 'aggregations') {
          continue;
        }
        if ($field_info['type'] instanceof ListOfType) {
          if (!($field_info['type']->ofType instanceof \Closure)
            && !($field_info['type']->ofType instanceof InterfaceType)
            && !($field_info['type']->ofType instanceof ObjectType)) {

            $args[$field] = [
              'type' => $field_info['type']->ofType,
              'name' => $field,
            ];
            $this->allArgTypes[get_class($field_info['type']->ofType)] = get_class($field_info['type']->ofType);
          }
          else if (
            ($field_info['type']->ofType instanceof InterfaceType
              || $field_info['type']->ofType instanceof ObjectType)
            && !empty($field_info['type']->ofType->config['__entity_type'])) {

            $args[$field] = [
              'type' => Type::int(),
              'name' => $field,
            ];
          }
          else {
            $type = get_class($field_info['type']->ofType);
            $this->addError("Type {$type} cannot be used as args for {$field}");
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
              if (!($col_info['type'] instanceof \Closure)
                && !($col_info['type'] instanceof InterfaceType)
                && !($col_info['type'] instanceof ObjectType)) {
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
    $entity_type_info = $this->getEntityInfo($entity_type);
    $defs = [
      'interface' => NULL,
      'objects' => []
    ];

    if (!isset($this->interfaceTypes[$entity_type])) {
      $defs['interface'] = $this->addInterfaceType($entity_type, [
        'name' => $entity_type,
        'description' => isset($entity_type_info['description']) ? $entity_type_info['description'] : '',
        'fields' => function () use($self, $entity_type) {
          return $self->getFields($entity_type);
        },
        'resolveType' => function ($obj) use ($entity_type, &$self) {
          $entity_type_info = $self->getEntityInfo($entity_type);
          if (count($entity_type_info['bundles']) === 1) {
            $bundle = key($entity_type_info['bundles']);
            return $self->objectTypes[$entity_type . '_' . $bundle];
          } else if ($obj === FALSE) {
            $bundle = key($entity_type_info['bundles']);
            return $self->objectTypes[$entity_type . '_' . $bundle];
          } else {
            $resolveType = null;
            list($id, $rid, $bundle) = entity_extract_ids($entity_type, $obj);
            if (isset($self->objectTypes[$entity_type . '_' . $bundle])) {
              $resolveType = $self->objectTypes[$entity_type . '_' . $bundle];
            }
            return $resolveType;
          }
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
          'interfaces' => [$this->interfaceTypes[$entity_type]],
          '__entity_bundle' => $bundle,
          '__entity_type' => $entity_type
        ]);
      }
      else {
        $defs['objects'][$bundle_name] = $this->objectTypes[$bundle_name];
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
    if (empty($this->interfaceTypes[$name])) {
      $this->interfaceTypes[$name] = new InterfaceType($info);
    }
    return $this->interfaceTypes[$name];
  }

  /**
   * Get GraphQL interface
   *
   * @param $name
   *
   * @return bool|mixed
   */
  public function getInterfaceType($name) {
    return isset($this->interfaceTypes[$name]) ? $this->interfaceTypes[$name] : FALSE;
  }

  /**
   * Add GraphQL object
   *
   * @param $name
   * @param $info
   * @param $override
   * @return mixed
   */
  public function addObjectType($name, $info, $override = false) {
    if (empty($this->objectTypes[$name]) || $override) {
      $this->objectTypes[$name] = new ObjectType($info);
    }
    return $this->objectTypes[$name];
  }

  public function addInputObjectType($name, $info, $override = false) {
    if (empty($this->objectTypes[$name]) || $override) {
      $this->objectTypes[$name] = new InputObjectType($info);
    }
    return $this->objectTypes[$name];
  }

  public function getObjectType($name) {
    return !empty($this->objectTypes[$name]) ? $this->objectTypes[$name] : FALSE;
  }


  public function addUnionType($name, $info) {
    if (empty($this->unionTypes[$name])) {
      $this->unionTypes[$name] = new UnionType($info);
    }
    return $this->unionTypes[$name];
  }

  public function getUnionType($name) {
    return !empty($this->unionTypes[$name]) ? $this->unionTypes[$name] : FALSE;
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
    $fields = &drupal_static(__CLASS__ . __METHOD__ . $entity_type);

    if (empty($fields)) {
      $fields = [];
      $properties_info = $this->getPropertyInfo($entity_type);
      if (!$properties_info) {
        return $fields;
      }

      $entity_info = $this->getEntityInfo($entity_type);
      $entity_keys = $entity_info['entity keys'];
      foreach ($properties_info['properties'] as $property => $info) {
        $fieldType = isset($info['type']) ? $this->gqlFieldType($info['type'], [
          'entity_type' => $entity_type,
          'bundle' => NULL,
          'property' => $property,
          'info' => $info
        ]) : Type::string();

        if (!$fieldType) {
          dump($info);
          die("Cannot detect fieldType for {$entity_type} {$property}");
          continue;
        }

        $fields[$property] = [
          'type' => $fieldType,
          'description' => isset($info['description']) ? $info['description'] : '',
          'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $property) {
            if (!$value) {
              return NULL;
            }

            if (($value instanceof \EntityDrupalWrapper)) {
              $wrap = $value;
            } else {
              if (method_exists($value, 'wrapper')) {
                $wrap = $value->wrapper();
              } else {
                $wrap = entity_metadata_wrapper($entity_type, $value);
              }
            }

            $items = $wrap->{$property}->value();

            if ($items === false) {
              return null;
            }
            
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
            
            if (isset($this->objectTypes[$field])) {
              $fieldType = $this->objectTypes[$field];
            } else {
              $fieldType = $this->gqlFieldType($field_info['type'], [
                'entity_type' => $entity_type,
                'bundle' => $bundle,
                'property' => $field,
                'info' => $field_info
              ]);
            }

            if (!$fieldType) {
              $this->addError("Cannot detect field type of {$field}");
              continue;
            }

            if (!is_callable($fieldType) && isset($this->interfaceTypes[$fieldType->name])) {
              $field_info = field_info_field($field);
              switch ($field_info['type']) {
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
                  $target_bundles = array_values($field_info['settings']['handler_settings']['target_bundles']);
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
              }
            }

            $fields[$field] = [
              'type' => $fieldType,
              'description' => isset($field_info['description']) ? $field_info['description'] : '',
              'resolve' => function ($value, $args, $context, ResolveInfo $info) use ($entity_type, $bundle, $field) {
                $wrap = entity_metadata_wrapper($entity_type, $value);
                if ($wrap->__isset($field)) {
                  $items = $wrap->{$field}->value();
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
   * Get GraphQL fields definition for given entity type / bundle to use when save
   *
   * @param $entity_type
   * @param string $bundle
   * @return array
   */
  public function getInputFields($entity_type, $bundle = '') {
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
      foreach ($properties_info['properties'] as $property => $info) {
        $fieldType = isset($info['type']) ? $this->gqlFieldType($info['type'], [
          'entity_type' => $entity_type,
          'bundle' => NULL,
          'property' => $property,
          'info' => $info
        ]) : Type::string();

        if (!$fieldType) {
          dump($info);
          die("Cannot detect fieldType for {$entity_type} {$property}");
          continue;
        }

        // input type do not accept object or interface! it must be id
        if ($fieldType instanceof InterfaceType || $fieldType instanceof ObjectType) {
          $fieldType = Type::id();
        } else if ($fieldType instanceof ListOfType && ($fieldType->ofType instanceof InterfaceType || $fieldType->ofType instanceof ObjectType) ) {
          $fieldType = Type::listOf(Type::id());
        }

        $fields[$property] = [
          'type' => $fieldType,
          'description' => isset($info['description']) ? $info['description'] : '',
        ];
        if (in_array($property, [
          $entity_keys['id'],
          $entity_keys['revision']
        ])) {
          $fields[$property]['type'] = Type::id();
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
              $this->addError("Cannot detect field type of {$field}");
              continue;
            }

            if (!is_callable($fieldType) && isset($this->interfaceTypes[$fieldType->name])) {
              $field_info = field_info_field($field);
              switch ($field_info['type']) {
                case 'field_collection':
                  break;
                case 'taxonomy_term_reference':
                  $fieldType = Type::id();
                  break;
                case 'entityreference':
                  $fieldType = $this->addObjectType('entityreference_input', [
                    'name' => 'entityreference_input',
                    'fields' => [
                      'id' => [
                        'name' => 'ID',
                        'type' => Type::id()
                      ]
                    ]
                  ]);
                  break;
              }
            }

            if ($fieldType instanceof InterfaceType || $fieldType instanceof ObjectType) {
              $fieldType = Type::id();
            } else if ($fieldType instanceof ListOfType && ($fieldType->ofType instanceof InterfaceType || $fieldType->ofType instanceof ObjectType) ) {
              $fieldType = ListOfType::int();
            }

            $fields[$field] = [
              'type' => $fieldType,
              'description' => isset($field_info['description']) ? $field_info['description'] : '',
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
    $type = &drupal_static($this->getCacheKey(__METHOD__, $context));

    if (!$type) {
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

      $type = $this->drupalToGqlFieldType($drupalType, $context);
    }

    return $type;
  }

  /**
   * Get cache key
   *
   * @param       $prefix
   * @param array $context
   *
   * @return string
   */
  private function getCacheKey($prefix, $context = []) {
    $cache_key = '';
    foreach ($context as $key => $val) {
      if (is_string($val)) {
        $cache_key .= $val;
      }
    }
    return $prefix .'_'. $cache_key;
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
    $type = &drupal_static($this->getCacheKey(__METHOD__, $context));

    if (!$type) {
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
        case 'db_date':
          $type = Type::string();
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
        $this->addError("Cannot convert {$drupalType} to GraphQL type. " . print_r($context, TRUE));
      }
    }

    return $type;
  }

  /**
   * Debug Drupal query
   *
   * @param $args
   * @param $query
   */
  public function debugQuery($args, $query) {
    if (variable_get('graphql_debug')) {
      dpm($args, '$args');
      dpq($query);
    }
  }

}
