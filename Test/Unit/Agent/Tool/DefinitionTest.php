<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent\Tool;

use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use PHPUnit\Framework\TestCase;

final class DefinitionTest extends TestCase
{
    private function buildDefinition(bool $takesStatus, array $inputSchema = []): Definition
    {
        return new Definition(
            'search_products',
            'Search the catalog',
            $inputSchema,
            'read',
            null,
            ['product_id'],
            true,
            $takesStatus,
            10
        );
    }

    public function testGetters(): void
    {
        $definition = $this->buildDefinition(true, ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]);
        $this->assertSame('search_products', $definition->getName());
        $this->assertSame('Search the catalog', $definition->getDescription());
        $this->assertSame('read', $definition->getKind());
        $this->assertNull($definition->getHandler());
        $this->assertSame(['product_id'], $definition->getProductIdArguments());
        $this->assertTrue($definition->remembersProducts());
        $this->assertTrue($definition->takesStatus());
        $this->assertSame(10, $definition->getSortOrder());
    }

    public function testApiDefinitionShape(): void
    {
        $definition = $this->buildDefinition(false, ['type' => 'object', 'properties' => []]);
        $apiDefinition = $definition->apiDefinition();
        $this->assertSame(['name', 'description', 'input_schema'], array_keys($apiDefinition));
        $this->assertSame('search_products', $apiDefinition['name']);
        $this->assertSame('Search the catalog', $apiDefinition['description']);
    }

    public function testApiDefinitionPrependsStatusWhenTakesStatus(): void
    {
        $definition = $this->buildDefinition(
            true,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]
        );
        $apiDefinition = $definition->apiDefinition();
        $properties = $apiDefinition['input_schema']['properties'];
        $this->assertSame(['status', 'query'], array_keys($properties));
        $this->assertSame(
            [
                'type' => 'string',
                'maxLength' => 60,
                'description' => 'A few plain words the customer sees while this runs',
            ],
            $properties['status']
        );
    }

    public function testApiDefinitionOmitsStatusWhenNotTakesStatus(): void
    {
        $definition = $this->buildDefinition(
            false,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]
        );
        $apiDefinition = $definition->apiDefinition();
        $this->assertArrayNotHasKey('status', $apiDefinition['input_schema']['properties']);
    }

    public function testStatusIsNeverRequired(): void
    {
        $definition = $this->buildDefinition(
            true,
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]
        );
        $apiDefinition = $definition->apiDefinition();
        $this->assertSame(['query'], $apiDefinition['input_schema']['required']);
        $this->assertNotContains('status', $apiDefinition['input_schema']['required']);
    }

    public function testApiDefinitionNormalizesEmptyPropertiesToAnObjectForJsonEncoding(): void
    {
        $definition = $this->buildDefinition(false, ['type' => 'object', 'properties' => []]);
        $apiDefinition = $definition->apiDefinition();
        $encoded = json_encode($apiDefinition);
        $this->assertIsString($encoded);
        $this->assertStringContainsString('"properties":{}', $encoded);
    }

    public function testApiDefinitionMergesStatusIntoStdClassProperties(): void
    {
        $definition = $this->buildDefinition(true, ['type' => 'object', 'properties' => new \stdClass()]);
        $apiDefinition = $definition->apiDefinition();
        $this->assertArrayHasKey('status', $apiDefinition['input_schema']['properties']);
    }
}
