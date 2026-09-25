<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private function definition(string $name, bool $takesStatus, int $sortOrder = 0): Definition
    {
        return new Definition(
            $name,
            'Description of ' . $name,
            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            'read',
            null,
            [],
            false,
            $takesStatus,
            $sortOrder
        );
    }

    private function provider(array $definitions): ToolProviderInterface
    {
        return new class ($definitions) implements ToolProviderInterface {
            public function __construct(private readonly array $definitions)
            {
            }

            public function getTools(AgentConfig $config): array
            {
                return $this->definitions;
            }
        };
    }

    private function storeConfigWithPolicyPages(string $policyPages): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'ai_integration/aiagent/content/policy_pages' ? $policyPages : null
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        return new StoreConfig($scopeConfig, $storeManager);
    }

    public function testCoreToolsComeFirstInTheirFixedOrder(): void
    {
        $core = $this->provider([
            $this->definition('load_skill', true, 1),
            $this->definition('search_products', true, 2),
        ]);
        $extension = $this->provider([$this->definition('custom_tool', true, 0)]);
        $registry = new Registry([$core, $extension], $this->storeConfigWithPolicyPages(''));
        $names = array_map(static fn (Definition $d): string => $d->getName(), $registry->definitions(1));
        $this->assertSame(['load_skill', 'search_products', 'custom_tool'], $names);
    }

    public function testExtensionToolsAfterCoreAreSortedBySortOrderThenName(): void
    {
        $core = $this->provider([$this->definition('load_skill', true, 1)]);
        $extension = $this->provider([
            $this->definition('zeta_tool', true, 5),
            $this->definition('alpha_tool', true, 5),
            $this->definition('early_tool', true, 1),
        ]);
        $registry = new Registry([$core, $extension], $this->storeConfigWithPolicyPages(''));
        $names = array_map(static fn (Definition $d): string => $d->getName(), $registry->definitions(1));
        $this->assertSame(['load_skill', 'early_tool', 'alpha_tool', 'zeta_tool'], $names);
    }

    public function testDuplicateNamesThrowLogicException(): void
    {
        $providerA = $this->provider([$this->definition('search_products', true, 1)]);
        $providerB = $this->provider([$this->definition('search_products', true, 2)]);
        $registry = new Registry([$providerA, $providerB], $this->storeConfigWithPolicyPages(''));
        $this->expectException(\LogicException::class);
        $registry->definitions(1);
    }

    public function testPoliciesOffRemovesSearchPolicies(): void
    {
        $core = $this->provider([
            $this->definition('search_policies', true, 1),
            $this->definition('search_products', true, 2),
        ]);
        $registry = new Registry([$core], $this->storeConfigWithPolicyPages(''));
        $names = array_map(static fn (Definition $d): string => $d->getName(), $registry->definitions(1));
        $this->assertNotContains('search_policies', $names);
        $this->assertContains('search_products', $names);
    }

    public function testPoliciesOnKeepsSearchPolicies(): void
    {
        $core = $this->provider([$this->definition('search_policies', true, 1)]);
        $registry = new Registry([$core], $this->storeConfigWithPolicyPages('about-us,returns'));
        $names = array_map(static fn (Definition $d): string => $d->getName(), $registry->definitions(1));
        $this->assertContains('search_policies', $names);
    }

    public function testByNameReturnsTheDefinitionOrNull(): void
    {
        $core = $this->provider([$this->definition('get_cart', true, 1)]);
        $registry = new Registry([$core], $this->storeConfigWithPolicyPages(''));
        $this->assertSame('get_cart', $registry->byName(1, 'get_cart')->getName());
        $this->assertNull($registry->byName(1, 'does_not_exist'));
    }

    public function testApiDefinitionsPrependStatusForTakesStatusTools(): void
    {
        $core = $this->provider([$this->definition('get_cart', true, 1)]);
        $registry = new Registry([$core], $this->storeConfigWithPolicyPages(''));
        $apiDefinitions = $registry->apiDefinitions(1);
        $this->assertSame(['name', 'description', 'input_schema'], array_keys($apiDefinitions[0]));
        $this->assertArrayHasKey('status', $apiDefinitions[0]['input_schema']['properties']);
    }

    public function testDefinitionsAreMemoisedPerStore(): void
    {
        $provider = new class implements ToolProviderInterface {
            public int $calls = 0;

            public function getTools(AgentConfig $config): array
            {
                $this->calls++;
                return [
                    new Definition(
                        'get_cart',
                        'Description of get_cart',
                        ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                        'read',
                        null,
                        [],
                        false,
                        true,
                        0
                    ),
                ];
            }
        };
        $registry = new Registry([$provider], $this->storeConfigWithPolicyPages(''));

        $registry->definitions(1);
        $registry->definitions(1);
        $registry->byName(1, 'get_cart');

        $this->assertSame(1, $provider->calls);
    }

    /**
     * StoreConfig::agent() (wave 0) does not wire enableOrders or enableFulfillment from
     * any config path, so those flags cannot be toggled through a real StoreConfig
     * instance. absentTools() is a pure function of AgentConfig, so it is exercised
     * directly here instead.
     */
    private function absentTools(AgentConfig $config): array
    {
        $registry = new Registry([], $this->storeConfigWithPolicyPages(''));
        $ref = new \ReflectionClass(Registry::class);
        $method = $ref->getMethod('absentTools');
        return $method->invoke($registry, $config);
    }

    public function testOrdersOffRemovesOrderTools(): void
    {
        $absent = $this->absentTools(new AgentConfig(enableOrders: false, enablePolicies: true));
        $this->assertSame(['get_orders', 'get_order_status', 'present_order_status'], $absent);
    }

    public function testFulfillmentOffRemovesFulfillmentOptions(): void
    {
        $absent = $this->absentTools(new AgentConfig(enableFulfillment: false, enablePolicies: true));
        $this->assertSame(['get_fulfillment_options'], $absent);
    }

    public function testEverythingOnRemovesNothing(): void
    {
        $absent = $this->absentTools(new AgentConfig(enablePolicies: true));
        $this->assertSame([], $absent);
    }
}
