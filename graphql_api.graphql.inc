<?php
/**
 * @file
 */
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;

/**
 * Implements hook_graphql_info().
 *
 * @return array
 */
function graphql_api_graphql_api_info() {
  return [
    'types' => [
      'text_formatted' => new ObjectType([
        'name' => 'text_formatted',
        'fields' => [
          'value' => array(
            'type' => Type::string(),
            'description' => t('Text'),
          ),
          'summary' => array(
            'type' => Type::string(),
            'description' => t('Summary'),
          ),
          'format' => array(
            'type' => Type::string(),
            'description' => t('Text format'),
          ),
        ]
      ]),
      'field_item_file' => new ObjectType([
        'name' => 'field_item_file',
        'fields' => function () {
          $schema = graphql_api();
          $file_type = $schema->getObjectType('file_file');
          if (module_exists('file_entity')) {
            $file_type = $schema->getInterfaceType('file');
          }
          return [
            'description' => [
              'type' => Type::string(),
              'description' => t('Alt')
            ],
            'display' => [
              'type' => Type::string(),
              'description' => t('Display')
            ],
            'file' => [
              'type' => $file_type,
              'description' => t('File')
            ]
          ];
        }
      ]),
      'field_item_image' => new ObjectType([
        'name' => 'field_item_image',
        'fields' => function () {
          $schema = graphql_api();
          $file_type = $schema->getObjectType('file_file');
          if (module_exists('file_entity')) {
            $file_type = $schema->getInterfaceType('file');
          }
          return [
            'description' => [
              'type' => Type::string(),
              'description' => t('Alt')
            ],
            'display' => [
              'type' => Type::string(),
              'description' => t('Display')
            ],
            'alt' => [
              'type' => Type::string(),
              'description' => t('Alt')
            ],
            'title' => [
              'type' => Type::string(),
              'description' => t('Title')
            ],
            'width' => [
              'type' => Type::float(),
              'description' => t('Width')
            ],
            'height' => [
              'type' => Type::float(),
              'description' => t('Height')
            ],
            'file' => [
              'type' => $file_type,
              'description' => t('File')
            ]
          ];
        }
      ]),
      'socialfield' => Type::listOf(new ObjectType([
        'name' => 'socialfield',
        'fields' => [
          'service' => [
            'type' => Type::string(),
            'description' => t('Service')
          ],
          'url' => [
            'type' => Type::string(),
            'description' => t('URL')
          ],
          'weight' => [
            'type' => Type::int(),
            'description' => t('Weight')
          ]
        ]
      ])),
      'field_item_name' => new ObjectType([
        'name' => 'field_item_name',
        'fields' => [
          'title' => [
            'type' => Type::string(),
            'description' => t('The title of the name.')
          ],
          'given' => [
            'type' => Type::string(),
            'description' => t('The given name.')
          ],
          'middle' => [
            'type' => Type::string(),
            'description' => t('The middle of the name.')
          ],
          'family' => [
            'type' => Type::string(),
            'description' => t('The family of the name.')
          ],
          'generational' => [
            'type' => Type::string(),
            'description' => t('The generational of the name.')
          ],
          'credentials' => [
            'type' => Type::string(),
            'description' => t('The credentials of the name.')
          ]
        ]
      ]),
      'field_item_link' => new ObjectType([
        'name' => 'field_item_link',
        'fields' => [
          'title' => [
            'type' => Type::string(),
            'description' => t('The title of the link.')
          ],
          'url' => [
            'type' => Type::string(),
            'description' => t('The URL of the link.')
          ],
          // @todo convert attributes to JSON?
//          'attributes' => [
//            'type' => Type::string(),
//            'description' => t('The attributes of the link.')
//          ],
        ]
      ])
    ]
  ];
}
