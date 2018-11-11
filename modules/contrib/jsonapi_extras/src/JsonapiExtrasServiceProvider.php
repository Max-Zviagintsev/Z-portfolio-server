<?php

namespace Drupal\jsonapi_extras;

use Drupal\Core\Config\BootstrapConfigStorageFactory;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\jsonapi_extras\ResourceType\ConfigurableResourceTypeRepository;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replace the resource type repository for our own configurable version.
 */
class JsonapiExtrasServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->has('jsonapi.resource_type.repository')) {
      // Override the class used for the configurable service.
      $definition = $container->getDefinition('jsonapi.resource_type.repository');
      $definition->setClass(ConfigurableResourceTypeRepository::class);
      // We can't use ConfigurableResourceTypeRepository::isJsonApi2x() here.
      // 'jsonapi.entity.to_jsonapi' service was removed in 2.x see
      // https://www.drupal.org/node/2983182.
      // @todo Remove this when JSON API Extras drops support for JSON API 1.x.
      if ($container->has('jsonapi.entity.to_jsonapi')) {
        $definition->addMethodCall('setStaticCache', [new Reference('cache.static')]);
      }
      // The configurable service expects the entity repository and the enhancer
      // plugin manager.
      $definition->addMethodCall('setEntityRepository', [new Reference('entity.repository')]);
      $definition->addMethodCall('setEnhancerManager', [new Reference('plugin.manager.resource_field_enhancer')]);
      $definition->addMethodCall('setConfigFactory', [new Reference('config.factory')]);
    }

    // Make all three of the normalizers that JSON API Extras overrides private
    // untagged services, to ensure that JSON API Extras' overrides continue to
    // work in JSON API 2.x, using core's @serializer service.
    if ($container->has('serializer.normalizer.field_item.jsonapi')) {
      $container->getDefinition('serializer.normalizer.field_item.jsonapi')->setPrivate(TRUE)->clearTags();
    }
    if ($container->has('serializer.normalizer.entity.jsonapi')) {
      $container->getDefinition('serializer.normalizer.entity.jsonapi')->setPrivate(TRUE)->clearTags();
    }
    if ($container->has('serializer.normalizer.config_entity.jsonapi')) {
      $container->getDefinition('serializer.normalizer.config_entity.jsonapi')->setPrivate(TRUE)->clearTags();
    }

    // Break a circular dependency.
    // @see \Drupal\jsonapi_extras\SerializerDecorator::lazilyInitialize()
    $definition = $container->getDefinition('jsonapi.serializer_do_not_use_removal_imminent');
    $definition->removeMethodCall('setFallbackNormalizer');

    $settings = BootstrapConfigStorageFactory::get()
      ->read('jsonapi_extras.settings');

    if ($settings !== FALSE) {
      $container->setParameter('jsonapi.base_path', '/' . $settings['path_prefix']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    $modules = $container->getParameter(('container.modules'));

    if (isset($modules['schemata_json_schema'])) {
      // Register field definition schema override.
      $container
        ->register('serializer.normalizer.field_definition.schema_json.jsonapi_extras', 'Drupal\jsonapi_extras\Normalizer\SchemaFieldDefinitionNormalizer')
        ->addTag('normalizer', ['priority' => 32])
        ->addArgument(new Reference('jsonapi.resource_type.repository'));

      // Register top-level schema override.
      $container
        ->register('serializer.normalizer.schemata_schema_normalizer.schema_json.jsonapi_extras', 'Drupal\jsonapi_extras\Normalizer\SchemataSchemaNormalizer')
        ->addTag('normalizer', ['priority' => 100])
        ->addArgument(new Reference('jsonapi.resource_type.repository'));
    }
  }

}
