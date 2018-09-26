<?php

namespace Drupal\Tests\jsonapi_extras\Kernel\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigException;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\Controller\EntityResource;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\jsonapi\Controller\EntityResource
 * @covers \Drupal\jsonapi_extras\Normalizer\ConfigEntityNormalizer
 * @requires function \Drupal\jsonapi\ResourceType\ResourceType::isMutable
 * @group jsonapi_extras
 * @group legacy
 *
 * @internal
 */
class EntityResourceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'node',
    'jsonapi',
    'serialization',
    'system',
    'user',
  ];

  /**
   * The EntityResource under test.
   *
   * @var \Drupal\jsonapi\Controller\EntityResource
   */
  protected $entityResource;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    NodeType::create([
      'type' => 'article',
    ])->save();
    Role::create([
      'id' => RoleInterface::ANONYMOUS_ID,
    ])->save();

    $this->entityResource = new EntityResource(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('jsonapi.link_manager'),
      $this->container->get('jsonapi.resource_type.repository'),
      $this->container->get('renderer'),
      $this->container->get('entity.repository')
    );
  }

  /**
   * @covers ::createIndividual
   */
  public function testCreateIndividualConfig() {
    $node_type = NodeType::create([
      'type' => 'test',
      'name' => 'Test Type',
      'description' => 'Lorem ipsum',
    ]);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('administer content types')
      ->save();
    $resource_type = new ResourceType('node', 'article', NULL);
    $response = $this->entityResource->createIndividual($resource_type, $node_type, new Request());
    // As a side effect, the node type will also be saved.
    $this->assertNotEmpty($node_type->id());
    $this->assertInstanceOf(JsonApiDocumentTopLevel::class, $response->getResponseData());
    $this->assertEquals('test', $response->getResponseData()->getData()->id());
    $this->assertEquals(201, $response->getStatusCode());
  }

  /**
   * @covers ::patchIndividual
   * @dataProvider patchIndividualConfigProvider
   */
  public function testPatchIndividualConfig($values) {
    // List of fields to be ignored.
    $ignored_fields = ['uuid', 'entityTypeId', 'type'];
    $node_type = NodeType::create([
      'type' => 'test',
      'name' => 'Test Type',
      'description' => '',
    ]);
    $node_type->save();

    $parsed_node_type = NodeType::create($values);
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('administer content types')
      ->save();
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('edit any article content')
      ->save();
    $payload = Json::encode([
      'data' => [
        'type' => 'node_type',
        'id' => $node_type->uuid(),
        'attributes' => $values,
      ],
    ]);
    $request = new Request([], [], [], [], [], [], $payload);

    $resource_type = new ResourceType('node', 'article', NULL);
    $response = $this->entityResource->patchIndividual($resource_type, $node_type, $parsed_node_type, $request);

    // As a side effect, the node will also be saved.
    $this->assertInstanceOf(JsonApiDocumentTopLevel::class, $response->getResponseData());
    $updated_node_type = $response->getResponseData()->getData();
    $this->assertInstanceOf(NodeType::class, $updated_node_type);
    // If the field is ignored then we should not see a difference.
    foreach ($values as $field_name => $value) {
      in_array($field_name, $ignored_fields) ?
        $this->assertNotSame($value, $node_type->get($field_name)) :
        $this->assertSame($value, $node_type->get($field_name));
    }
    $this->assertEquals(200, $response->getStatusCode());
  }

  /**
   * Provides data for the testPatchIndividualConfig.
   *
   * @return array
   *   The input data for the test function.
   */
  public function patchIndividualConfigProvider() {
    return [
      [['description' => 'PATCHED', 'status' => FALSE]],
      [[]],
    ];
  }

  /**
   * @covers ::patchIndividual
   * @dataProvider patchIndividualConfigFailedProvider
   */
  public function testPatchIndividualFailedConfig($values) {
    $this->setExpectedException(ConfigException::class);
    $this->testPatchIndividualConfig($values);
  }

  /**
   * Provides data for the testPatchIndividualFailedConfig.
   *
   * @return array
   *   The input data for the test function.
   */
  public function patchIndividualConfigFailedProvider() {
    return [
      [['uuid' => 'PATCHED']],
      [['type' => 'article', 'status' => FALSE]],
    ];
  }

  /**
   * @covers ::deleteIndividual
   */
  public function testDeleteIndividualConfig() {
    $node_type = NodeType::create([
      'type' => 'test',
      'name' => 'Test Type',
      'description' => 'Lorem ipsum',
    ]);
    $id = $node_type->id();
    $node_type->save();
    Role::load(Role::ANONYMOUS_ID)
      ->grantPermission('administer content types')
      ->save();
    $resource_type = new ResourceType('node', 'article', NULL);
    $response = $this->entityResource->deleteIndividual($resource_type, $node_type, new Request());
    // As a side effect, the node will also be deleted.
    $count = $this->container->get('entity_type.manager')
      ->getStorage('node_type')
      ->getQuery()
      ->condition('type', $id)
      ->count()
      ->execute();
    $this->assertEquals(0, $count);
    $this->assertNull($response->getResponseData());
    $this->assertEquals(204, $response->getStatusCode());
  }

}
