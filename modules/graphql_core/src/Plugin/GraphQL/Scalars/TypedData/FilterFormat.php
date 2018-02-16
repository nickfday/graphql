<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Scalars\TypedData;

use Drupal\graphql\Plugin\GraphQL\PluggableSchemaBuilder;
use Drupal\graphql\Plugin\GraphQL\Scalars\ScalarPluginBase;
use GraphQL\Type\Definition\Type;

/**
 * @GraphQLScalar(
 *   id = "filter_format",
 *   name = "FilterFormat",
 *   type = "filter_format"
 * )
 */
class FilterFormat extends ScalarPluginBase {

  /**
   * {@inheritdoc}
   */
  public static function createInstance(PluggableSchemaBuilder $builder, $definition, $id) {
    return Type::string();
  }
}