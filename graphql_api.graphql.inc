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
      'field_item_link' => new ObjectType([
        'name' => 'field_item_link',
        'fields' => function() {
          return [
            'title' => [
              'type' => Type::string(),
              'description' => t('The title of the link.')
            ],
            'url' => [
              'type' => Type::string(),
              'description' => t('The URL of the link.')
            ],
          ];
        }
      ]),
      'text_formatted'   => new ObjectType(
        [
          'name'   => 'text_formatted',
          'fields' => [
            'value'   => [
              'type'        => Type::string(),
              'description' => t('Text'),
            ],
            'summary' => [
              'type'        => Type::string(),
              'description' => t('Summary'),
            ],
            'format'  => [
              'type'        => Type::string(),
              'description' => t('Text format'),
            ],
          ],
        ]
      ),
      'image_styles' => new ObjectType([
        'name'   => 'image_styles',
        'fields' => function () {
          $fields = [];
          foreach (image_styles() as $style_name => $info) {
            $field_name = preg_replace('/[^_a-zA-Z0-9]/', '_', $style_name);
            if (preg_match('/^[_a-zA-Z][_a-zA-Z0-9]*$/', $field_name)) {
              $fields[$field_name] = [
                'type' => Type::string(),
                'description' => $info['label']
              ];
            } else {
              watchdog('GraphQL', "image_styles '{$style_name}' is ignored, not match pattern /^[_a-zA-Z][_a-zA-Z0-9]*$/");
            }
          }
          return $fields;
        }
      ]),
      'field_item_file'  => new ObjectType(
        [
          'name'   => 'field_item_file',
          'fields' => function () {
            graphql_api()->addEntityGqlDefinition('file');
            $type = graphql_api()->getInterfaceType('file');
            return [
              'file'        => [
                'type'        => $type,
                'description' => t('File'),
                'resolve'     => function ($value) {
                  if ($value) {
                    $file = file_load($value['fid']);
                    return entity_metadata_wrapper('file', $file);
                  }
                  return NULL;
                },
              ],
              'description' => [
                'type'        => Type::string(),
                'description' => t('Alt'),
              ],
              'display'     => [
                'type'        => Type::string(),
                'description' => t('Display'),
              ],
            ];
          },
        ]
      ),
      'field_item_image' => new ObjectType(
        [
          'name'   => 'field_item_image',
          'fields' => function () {
            // graphql_api()->addEntityGqlDefinition('file');
            // $type = graphql_api()->getObjectType('file');
            return [
              'fid'         => [
                'type'        => Type::string(),
                'description' => t('File ID'),
              ],
              'description' => [
                'type'        => Type::string(),
                'description' => t('Alt'),
              ],
              'display'     => [
                'type'        => Type::string(),
                'description' => t('Display'),
              ],
              'alt'         => [
                'type'        => Type::string(),
                'description' => t('Alt'),
              ],
              'title'       => [
                'type'        => Type::string(),
                'description' => t('Title'),
              ],
            ];
          },
        ]
      )
    ],
  ];
}