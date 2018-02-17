<?php

namespace Drupal\graphql\Plugin;

use Drupal\Component\Plugin\PluginManagerInterface;

class SchemaBuilder {

  /**
   * @var \Drupal\graphql\Plugin\FieldPluginManager
   */
  protected $fieldManager;

  /**
   * @var \Drupal\graphql\Plugin\MutationPluginManager
   */
  protected $mutationManager;

  /**
   * @var \Drupal\graphql\Plugin\TypePluginManager[]
   */
  protected $typeManagers;

  /**
   * @var array
   */
  protected $fields;

  /**
   * @var array
   */
  protected $mutations;

  /**
   * @var array
   */
  protected $types;

  /**
   * SchemaBuilder constructor.
   *
   * @param \Drupal\graphql\Plugin\FieldPluginManager $fieldManager
   * @param \Drupal\graphql\Plugin\MutationPluginManager $mutationManager
   */
  public function __construct(
    FieldPluginManager $fieldManager,
    MutationPluginManager $mutationManager
  ) {
    $this->fieldManager = $fieldManager;
    $this->mutationManager = $mutationManager;
  }

  /**
   * Registers a plugin manager.
   *
   * @param \Drupal\Component\Plugin\PluginManagerInterface $pluginManager
   *   The plugin manager to register.
   * @param $id
   *   The id of the service.
   */
  public function addTypeManager(PluginManagerInterface $pluginManager, $id) {
    $pieces = explode('.', $id);
    $type = end($pieces);

    $this->typeManagers[$type] = $pluginManager;
  }

  /**
   * @return bool
   */
  public function hasFields($parent) {
    return isset($this->getFieldAssociationMap()[$parent]);
  }

  /**
   * @return bool
   */
  public function hasMutations() {
    return !empty($this->getMutationMap());
  }

  /**
   * @return bool
   */
  public function hasType($name) {
    return isset($this->getTypeMap()[$name]);
  }

  /**
   * @return array
   */
  public function getFields($parent) {
    $associations = $this->getFieldAssociationMap();
    if (isset($associations[$parent])) {
      $map = $this->getFieldMap();
      return $this->processFields(array_map(function ($id) use ($map) {
        return $map[$id];
      }, $associations[$parent]));
    }

    return [];
  }

  /**
   * @return array
   */
  public function getMutations() {
    return $this->processMutations($this->getMutationMap());
  }

  /**
   * @return array
   */
  public function getTypes() {
    return array_map(function ($name) {
      return $this->getType($name);
    }, array_keys($this->getTypeMap()));
  }

  /**
   * @param $name
   *
   * @return mixed
   */
  public function getType($name) {
    $types = $this->getTypeMap();
    if (isset($types[$name])) {
      return $this->buildType($types[$name]);
    }

    $references = $this->getTypeReferenceMap();
    do {
      if (isset($references[$name])) {
        return $this->buildType($types[$references[$name]]);
      }
    } while (($pos = strpos($name, ':')) !== FALSE && $name = substr($name, 0, $pos));

    throw new \LogicException(sprintf('Missing type %s.', $name));
  }

  /**
   * @param $fields
   *
   * @return array
   */
  public function processFields($fields) {
    return array_map([$this, 'buildField'], $fields);
  }

  /**
   * @param $args
   *
   * @return array
   */
  public function processArguments($args) {
    return array_map(function ($arg) {
      return [
        'type' => $this->processType($arg['type']),
      ] + $arg;
    }, $args);
  }

  /**
   * @param $type
   *
   * @return mixed
   */
  public function processType($type) {
    list($type, $decorators) = $type;

    return array_reduce($decorators, function ($type, $decorator) {
      return $decorator($type);
    }, $this->getType($type));
  }

  /**
   * @return array
   */
  protected function getTypeMap() {
    if (!isset($this->typeMap)) {
      $this->typeMap = $this->buildTypeMap();
    }

    return $this->typeMap;
  }

  /**
   * @param $type
   *
   * @return mixed
   */
  protected function buildType($type) {
    if (!isset($this->types[$type['id']])) {
      $creator = [$type['class'], 'createInstance'];
      $this->types[$type['id']] = $creator($this, $this->typeManagers[$type['type']], $type['definition'], $type['id']);
    }

    return $this->types[$type['id']];
  }

  /**
   * @param $field
   *
   * @return mixed
   */
  protected function buildField($field) {
    if (!isset($this->fields[$field['id']])) {
      $creator = [$field['class'], 'createInstance'];
      $this->fields[$field['id']] = $creator($this, $this->fieldManager, $field['definition'], $field['id']);
    }

    return $this->fields[$field['id']];
  }

  /**
   * @param $mutation
   *
   * @return mixed
   */
  protected function buildMutation($mutation) {
    if (!isset($this->mutations[$mutation['id']])) {
      $creator = [$mutation['class'], 'createInstance'];
      $this->fields[$mutation['id']] = $creator($this, $this->mutationManager, $mutation['definition'], $mutation['id']);
    }

    return $this->mutations[$mutation['id']];
  }

  /**
   * @return array
   */
  protected function buildTypeMap() {
    // First collect all definitions by their name, overwriting those with
    // lower weights by their higher weighted counterparts. We also collect
    // the class from the plugin definition to be able to statically create
    // the type instance without loading the plugin managers at all at
    // run-time.
    $types = array_reduce(array_keys($this->typeManagers), function ($carry, $type) {
      $manager = $this->typeManagers[$type];
      $definitions = $manager->getDefinitions();

      return array_reduce(array_keys($definitions), function ($carry, $id) use ($type, $definitions) {
        $current = $definitions[$id];
        $name = $current['name'];

        if (empty($carry[$name]) || $carry[$name]['weight'] < $current['weight']) {
          $carry[$name] = [
            'type' => $type,
            'id' => $id,
            'class' => $current['class'],
            'weight' => !empty($current['weight']) ? $current['weight'] : 0,
            'reference' => !empty($current['type']) ? $current['type'] : NULL,
          ];
        }

        return $carry;
      }, $carry);
    }, []);

    // Retrieve the plugins run-time definitions. These will help us to prevent
    // plugin instantiation at run-time unless a plugin is actually called from
    // the graphql query execution. Plugins should take care of not having to
    // instantiate their plugin instances during schema composition.
    return array_map(function ($type) {
      $manager = $this->typeManagers[$type['type']];
      /** @var \Drupal\graphql\Plugin\TypePluginInterface $instance */
      $instance = $manager->createInstance($type['id']);

      return [
        'definition' => $instance->getDefinition(),
      ] + $type;
    }, $types);
  }

  /**
   * @return array
   */
  protected function getTypeReferenceMap() {
    if (!isset($this->typeReferenceMap)) {
      $this->typeReferenceMap = $this->buildTypeReferenceMap();
    }

    return $this->typeReferenceMap;
  }

  /**
   * @return array
   */
  protected function buildTypeReferenceMap() {
    $types = $this->getTypeMap();
    $references = array_reduce(array_keys($types), function ($references, $name) use ($types) {
      $current = $types[$name];
      $reference = $current['reference'];

      if (!empty($reference) && (empty($references[$reference]) || $references[$reference]['weight'] < $current['weight'])) {
        $references[$reference] = [
          'name' => $name,
          'weight' => !empty($current['weight']) ? $current['weight'] : 0,
        ];
      }

      return $references;
    }, []);

    return array_map(function ($reference) {
      return $reference['name'];
    }, $references);
  }

  /**
   * @return array
   */
  protected function getFieldAssociationMap() {
    if (!isset($this->fieldAssociationMap)) {
      $this->fieldAssociationMap = $this->buildFieldAssociationMap();
    }

    return $this->fieldAssociationMap;
  }

  /**
   * @return array
   */
  protected function buildFieldAssociationMap() {
    $definitions = $this->fieldManager->getDefinitions();

    $fields = array_reduce(array_keys($definitions), function ($carry, $id) use ($definitions) {
      $current = $definitions[$id];
      $parents = $current['parents'] ?: ['Root'];

      return array_reduce($parents, function ($carry, $parent) use ($current, $id) {
        // Allow plugins to define a different name for each parent.
        if (strpos($parent, ':') !== FALSE) {
          list($parent, $name) = explode(':', $parent);
        }

        $name = isset($name) ? $name : $current['name'];
        if (empty($carry[$parent][$name]) || $carry[$parent][$name]['weight'] < $current['weight']) {
          $carry[$parent][$name] = [
            'id' => $id,
            'weight' => !empty($current['weight']) ? $current['weight'] : 0,
          ];
        }

        return $carry;
      }, $carry);
    }, []);

    // Only return fields for types that are actually fieldable.
    $fieldable = [GRAPHQL_TYPE_PLUGIN, GRAPHQL_INTERFACE_PLUGIN];
    $fields = array_intersect_key($fields, array_filter($this->getTypeMap(), function ($type) use ($fieldable) {
      return in_array($type['type'], $fieldable);
    }) + ['Root' => NULL]);

    // We only need the plugin ids in this map.
    return array_map(function ($fields) {
      return array_map(function ($field) {
        return $field['id'];
      }, $fields);
    }, $fields);
  }

  /**
   * @return array
   */
  protected function getFieldMap() {
    if (!isset($this->fieldMap)) {
      $this->fieldMap = $this->buildFieldMap();
    }

    return $this->fieldMap;
  }

  /**
   * @return array
   */
  protected function buildFieldMap() {
    $association = $this->getFieldAssociationMap();
    return array_reduce($association, function ($carry, $fields) {
      return array_reduce($fields, function ($carry, $id) {
        if (!isset($carry[$id])) {
          $instance = $this->fieldManager->createInstance($id);
          $definition = $this->fieldManager->getDefinition($id);

          $carry[$id] = [
            'id' => $id,
            'class' => $definition['class'],
            'definition' => $instance->getDefinition(),
          ];
        }

        return $carry;
      }, $carry);
    }, []);
  }

  /**
   * @return array
   */
  protected function getMutationMap() {
    if (!isset($this->mutationMap)) {
      $this->mutationMap = $this->buildMutationMap();
    }

    return $this->mutationMap;
  }

  /**
   * @return array
   */
  protected function buildMutationMap() {
    $mutations = $this->mutationManager->getDefinitions();
    $mutations = array_reduce(array_keys($mutations), function ($carry, $id) use ($mutations) {
      $current = $mutations[$id];
      $name = $current['name'];

      if (empty($carry[$name]) || $carry[$name]['weight'] < $current['weight']) {
        $carry[$name] = [
          'id' => $id,
          'class' => $current['class'],
          'weight' => !empty($current['weight']) ? $current['weight'] : 0,
        ];
      }

      return $carry;
    }, []);

    return array_map(function ($definition) {
      $id = $definition['id'];
      $instance = $this->mutationManager->createInstance($id);

      $carry[$id] = [
        'id' => $id,
        'definition' => $instance->getDefinition(),
      ];
    }, $mutations);
  }
}
