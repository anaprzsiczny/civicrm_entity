<?php

namespace Drupal\civicrm_entity;

use Drupal\Core\Entity\EntityTypeInterface;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\EntityViewsData;

class CivicrmEntityViewsData extends EntityViewsData {

  use StringTranslationTrait;

  public function getViewsData() {
    $data = [];
    $base_table = $this->entityType->getBaseTable() ?: $this->entityType->id();
    $base_field = $this->entityType->getKey('id');

    // Setup base information of the views data.
    $data[$base_table]['table']['group'] = sprintf('%s (CiviCRM Entity)', $this->entityType->getLabel());
    $data[$base_table]['table']['provider'] = $this->entityType->getProvider();
    $data[$base_table]['table']['entity type'] = $this->entityType->id();

    $views_base_table = $base_table;
    $data[$views_base_table]['table']['base'] = [
      'field' => $base_field,
      'title' => $this->entityType->getLabel(),
      'cache_contexts' => $this->entityType->getListCacheContexts(),
    ];
    $data[$base_table]['table']['entity revision'] = FALSE;
    if ($label_key = $this->entityType->getKey('label')) {
      $data[$views_base_table]['table']['base']['defaults'] = [
        'field' => $label_key,
      ];
    }

    // Entity types must implement a list_builder in order to use Views'
    // entity operations field.
    if ($this->entityType->hasListBuilderClass()) {
      $data[$base_table]['operations'] = [
        'field' => [
          'title' => $this->t('Operations links'),
          'help' => $this->t('Provides links to perform entity operations.'),
          'id' => 'entity_operations',
        ],
      ];
    }

    if ($this->entityType->hasViewBuilderClass()) {
      $data[$base_table]['rendered_entity'] = [
        'field' => [
          'title' => $this->t('Rendered entity'),
          'help' => $this->t('Renders an entity in a view mode.'),
          'id' => 'rendered_entity',
        ],
      ];
    }

    $this->addEntityLinks($data[$base_table]);

    // Load all typed data definitions of all fields. This should cover each of
    // the entity base, revision, data tables.
    $field_definitions = $this->entityManager->getBaseFieldDefinitions($this->entityType->id());
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
    $table_mapping = $this->storage->getTableMapping();
    if ($table_mapping) {
      foreach ($field_definitions as $field_definition) {
        if ($table_mapping->allowsSharedTableStorage($field_definition->getFieldStorageDefinition())) {
          $this->mapFieldDefinition($views_base_table, $field_definition->getName(), $field_definition, $table_mapping, $data[$views_base_table]);

          // Provide a reverse relationship for the entity type that is referenced by
          // the field.
          if ($field_definition->getType() === 'entity_reference') {
            $target_entity_type_id = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
            $target_entity_type = $this->entityManager->getDefinition($target_entity_type_id);
            assert($target_entity_type !== NULL);
            $target_base_table = $target_entity_type->getDataTable() ?: $target_entity_type->getBaseTable();

            $field_name = $field_definition->getName();
            $pseudo_field_name = 'reverse__' . $this->entityType->id() . '__' . $field_name;
            $args = [
              '@label' => $target_entity_type->getLowercaseLabel(),
              '@field_name' => $field_name,
              '@entity' => $this->entityType->getLabel(),
            ];
            $data[$target_base_table][$pseudo_field_name]['relationship'] = [
              'title' => $this->t('@entity using @field_name', $args),
              'label' => $this->t('@field_name', ['@field_name' => $field_name]),
              'group' => $target_entity_type->getLabel(),
              'help' => $this->t('Relate each @entity with a @field_name set to the @label.', $args),
              'id' => 'civicrm_entity_reverse',
              'base' => $this->entityType->getDataTable() ?: $this->entityType->getBaseTable(),
              'entity_type' => $this->entityType->id(),
              'base field' => $this->entityType->getKey('id'),
              'field_name' => $field_name,
            ];
          }
        }
        else if ($table_mapping->requiresDedicatedTableStorage($field_definition->getFieldStorageDefinition())) {
          $table = $table_mapping->getDedicatedDataTableName($field_definition->getFieldStorageDefinition());

          $data[$table]['table']['group'] = $this->entityType->getLabel();
          $data[$table]['table']['provider'] = $this->entityType->getProvider();
          $data[$table]['table']['join'][$views_base_table] = [
            'left_field' => $base_field,
            'field' => 'entity_id',
            'extra' => [
              ['field' => 'deleted', 'value' => 0, 'numeric' => TRUE],
            ],
          ];
        }
      }
    }

    $this->processViewsDataForSpecialFields($data, $base_table);

    return $data;
  }

  public function getViewsTableForEntityType(EntityTypeInterface $entity_type) {
    // CiviCRM Entity tables are `civicrm_*`
    return $entity_type->id();
  }

  /**
   * Provides Views integration for any datetime-based fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see datetime_type_field_views_data_helper()
   */
  protected function processViewsDataForDatetime($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    // Set the 'datetime' filter type.
    $views_field['filter']['id'] = 'datetime';
    $views_field['filter']['field_name'] = $field_definition->getName();

    // Set the 'datetime' argument type.
    $views_field['argument']['id'] = 'datetime';
    $views_field['argument']['field_name'] = $field_definition->getName();

    // Set the 'datetime' sort handler.
    $views_field['sort']['id'] = 'datetime';
    $views_field['sort']['field_name'] = $field_definition->getName();
  }

  /**
   * Provides Views integration for list_string fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see options_field_views_data()
   */
  protected function processViewsDataForListString($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    $views_field['filter']['id'] = 'list_field';
    $views_field['filter']['field_name'] = $field_definition->getName();
    $views_field['argument']['id'] = 'string_list_field';
    $views_field['argument']['field_name'] = $field_definition->getName();
  }

  /**
   * Provides Views integration for list_integer fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see options_field_views_data()
   */
  protected function processViewsDataForListInteger($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    $views_field['filter']['id'] = 'list_field';
    $views_field['filter']['field_name'] = $field_definition->getName();
    $views_field['argument']['id'] = 'number_list_field';
    $views_field['argument']['field_name'] = $field_definition->getName();
  }

  /**
   * Add views integration for fields that require special handling.
   *
   * @param array $views_field
   *   Array of fields from ::getViewsData().
   * @param string $base_table
   *   The base table, most likely the CiviCRM entity type.
   */
  protected function processViewsDataForSpecialFields(array &$views_field, $base_table) {
    switch ($base_table) {
      case 'civicrm_contact':
        $views_field['civicrm_contact']['user'] = [
          'title' => $this->t('User related to the CiviCRM contact'),
          'help' => $this->t('Relate user to the CiviCRM contact.'),
          'relationship' => [
            'base' => 'users_field_data',
            'base field' => 'uid',
            'first field' => 'contact_id',
            'second field' => 'uf_id',
            'id' => 'civicrm_entity_civicrm_contact_user',
            'label' => $this->t('User'),
          ],
        ];

        $views_field['users_field_data']['civicrm_contact'] = [
          'title' => $this->t('CiviCRM contact related to the user'),
          'help' => $this->t('Relate CiviCRM contact to the user.'),
          'relationship' => [
            'base' => 'civicrm_contact',
            'base field' => 'id',
            'first field' => 'uf_id',
            'second field' => 'contact_id',
            'id' => 'civicrm_entity_civicrm_contact_user',
            'label' => $this->t('CiviCRM contact'),
          ],
        ];

        break;
    }
  }

}