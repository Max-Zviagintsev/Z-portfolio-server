<?php

namespace Drupal\jsonapi_extras\ResourceType;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides a repository of JSON API configurable resource types.
 */
class ConfigurableResourceTypeRepository extends ResourceTypeRepository {

  /**
   * {@inheritdoc}
   *
   * @todo Remove this when JSON API Extras drops support for JSON API 1.x.
   */
  const RESOURCE_TYPE_CLASS = ConfigurableResourceType::class;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Plugin manager for enhancers.
   *
   * @var \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager
   */
  protected $enhancerManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * A list of all resource types.
   *
   * @var \Drupal\jsonapi_extras\ResourceType\ConfigurableResourceType[]
   */
  protected $resourceTypes;

  /**
   * A list of only enabled resource types.
   *
   * @var \Drupal\jsonapi_extras\ResourceType\ConfigurableResourceType[]
   */
  protected $enabledResourceTypes;

  /**
   * A list of all resource configuration entities.
   *
   * @var \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig[]
   */
  protected $resourceConfigs;

  /**
   * The static cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $staticCache;

  /**
   * Detects whether this site has JSON API 1.x or 2.x installed.
   *
   * @return bool
   *   TRUE if JSON API 2.x is installed. FALSE otherwise.
   *
   * @todo Remove this when JSON API Extras drops support for JSON API 1.x.
   */
  public static function isJsonApi2x() {
    $v = ModuleHandler::parseDependency('jsonapi(>= 8.x-2.0-beta1)');
    $module_list = \Drupal::service('extension.list.module')->getList();
    return is_null(drupal_check_incompatibility($v, $module_list['jsonapi']->info['version']));
  }

  /**
   * Injects the entity repository.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function setEntityRepository(EntityRepositoryInterface $entity_repository) {
    $this->entityRepository = $entity_repository;
  }

  /**
   * Injects the cache back-end.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache back-end.
   */
  public function setStaticCache(CacheBackendInterface $cache_backend) {
    $this->staticCache = $cache_backend;
  }

  /**
   * Injects the resource enhancer manager.
   *
   * @param \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager $enhancer_manager
   *   The resource enhancer manager.
   */
  public function setEnhancerManager(ResourceFieldEnhancerManager $enhancer_manager) {
    $this->enhancerManager = $enhancer_manager;
  }

  /**
   * Injects the configuration factory.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   */
  public function setConfigFactory(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   *
   * @todo Remove this when JSON API Extras drops support for JSON API 1.x.
   */
  public function all() {
    if (static::isJsonApi2x()) {
      return parent::all();
    }

    if (!$this->all) {
      $all = parent::all();
      array_walk($all, [$this, 'injectAdditionalServicesToResourceType']);
      $this->all = $all;
    }
    return $this->all;
  }

  /**
   * {@inheritdoc}
   *
   * Mostly the same as the parent implementation, with three key differences:
   * 1. Different resource type class.
   * 2. Every resource type is assumed to be mutable.
   * 2. Field mapping not based on logic, but on configuration.
   */
  protected function createResourceType(EntityTypeInterface $entity_type, $bundle) {
    $resource_config_id = sprintf(
      '%s--%s',
      $entity_type->id(),
      $bundle
    );
    $resource_config = $this->getResourceConfig($resource_config_id);

    // Create subclassed ResourceType object with the same parameters as the
    // parent implementation.
    $resource_type = new ConfigurableResourceType(
      $entity_type->id(),
      $bundle,
      $entity_type->getClass(),
      $entity_type->isInternal() || (bool) $resource_config->get('disabled'),
      static::isLocatableResourceType($entity_type, $bundle),
      TRUE,
      $resource_config->getFieldMapping()
    );

    // Inject additional services through setters. By using setter injection
    // rather that constructor injection, we prevent future BC breaks.
    $resource_type->setJsonapiResourceConfig($resource_config);
    $resource_type->setEnhancerManager($this->enhancerManager);
    $resource_type->setConfigFactory($this->configFactory);
    $resource_type->setStaticCache($this->staticCache);

    return $resource_type;
  }

  /**
   * Injects a additional services into the configurable resource type.
   *
   * @param \Drupal\jsonapi_extras\ResourceType\ConfigurableResourceType $resource_type
   *   The resource type.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *
   * @todo Remove this when JSON API Extras drops support for JSON API 1.x.
   */
  protected function injectAdditionalServicesToResourceType(ConfigurableResourceType $resource_type) {
    $resource_config_id = sprintf(
      '%s--%s',
      $resource_type->getEntityTypeId(),
      $resource_type->getBundle()
    );
    $resource_config = $this->getResourceConfig($resource_config_id);
    $resource_type->setJsonapiResourceConfig($resource_config);
    $resource_type->setEnhancerManager($this->enhancerManager);
    $resource_type->setConfigFactory($this->configFactory);
    $resource_type->setStaticCache($this->staticCache);
    $entity_type = $this
      ->entityTypeManager
      ->getDefinition($resource_type->getEntityTypeId());
    $is_internal = static:: shouldBeInternalResourceType($entity_type)
      || (bool) $resource_config->get('disabled');
    $resource_type->setInternal($is_internal);
  }

  /**
   * Get a single resource configuration entity by its ID.
   *
   * @param string $resource_config_id
   *   The configuration entity ID.
   *
   * @return \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig
   *   The configuration entity for the resource type.
   */
  protected function getResourceConfig($resource_config_id) {
    try {
      $resource_configs = $this->getResourceConfigs();
      return isset($resource_configs[$resource_config_id]) ?
        $resource_configs[$resource_config_id] :
        new NullJsonapiResourceConfig([], '');
    }
    catch (PluginException $e) {
      return new NullJsonapiResourceConfig([], '');
    }
  }

  /**
   * Load all resource configuration entities.
   *
   * @return \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig[]
   *   The resource config entities.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getResourceConfigs() {
    if (!$this->resourceConfigs) {
      $resource_config_ids = [];
      foreach ($this->getEntityTypeBundleTuples() as $tuple) {
        list($entity_type_id, $bundle) = $tuple;
        $resource_config_ids[] = sprintf('%s--%s', $entity_type_id, $bundle);
      }
      $this->resourceConfigs = $this->entityTypeManager
        ->getStorage('jsonapi_resource_config')
        ->loadMultiple($resource_config_ids);
    }
    return $this->resourceConfigs;
  }

  /**
   * Entity type ID and bundle iterator.
   *
   * @return array
   *   A list of entity type ID and bundle tuples.
   */
  protected function getEntityTypeBundleTuples() {
    $entity_type_ids = array_keys($this->entityTypeManager->getDefinitions());
    // For each entity type return as many tuples as bundles.
    return array_reduce($entity_type_ids, function ($carry, $entity_type_id) {
      $bundles = array_keys($this->entityTypeBundleInfo->getBundleInfo($entity_type_id));
      // Get all the tuples for the current entity type.
      $tuples = array_map(function ($bundle) use ($entity_type_id) {
        return [$entity_type_id, $bundle];
      }, $bundles);
      // Append the tuples to the aggregated list.
      return array_merge($carry, $tuples);
    }, []);
  }

  /**
   * Resets the internal caches for resource types and resource configs.
   */
  public function reset() {
    $this->all = [];
    $this->resourceConfigs = [];
  }

}
