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
        'fields' => [
          'file' => [
            'type' => Type::string(),
            'description' => t('File')
          ],
          'description' => [
            'type' => Type::string(),
            'description' => t('Alt')
          ],
          'display' => [
            'type' => Type::string(),
            'description' => t('Display')
          ]
        ]
      ]),
      'field_item_image' => new ObjectType([
        'name' => 'field_item_image',
        'fields' => [
          'file' => [
            'type' => Type::string(),
            'description' => t('File')
          ],
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
          ]
        ]
      ]),
      'socialfield' => new ObjectType([
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
      ]),
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
      ])
    ]
  ];
}
