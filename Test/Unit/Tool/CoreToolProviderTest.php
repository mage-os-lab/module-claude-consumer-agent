<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\CartWrite;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Options;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\CoreToolProvider;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\AddToCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetFulfillmentOptions;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrders;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetPreferences;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetProductDetails;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\LoadSkill;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\MemoryOff;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\RemoveFromCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchCategories;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchPolicies;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchProducts;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\UpdateCartItem;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class CoreToolProviderTest extends TestCase
{
    private function realSkillRegistry(): SkillRegistry
    {
        $loader = new Loader(
            [['module' => 'MageOS_AiShoppingAssistant', 'path' => 'skills', 'sortOrder' => 0]],
            new ComponentRegistrar(),
            new File(),
            new FrontMatter()
        );
        return new SkillRegistry($loader);
    }

    private function buildProvider(): CoreToolProvider
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $fence = new Fence(new Sanitizer());
        $serializer = new Serializer($fence);
        $lockManager = $this->createMock(LockManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $cartWrite = new CartWrite(
            $backend,
            $lockManager,
            new Options(new Sanitizer()),
            new Provenance(),
            $serializer,
            $logger
        );
        $skills = $this->realSkillRegistry();
        $presentation = new PresentationRegistry(
            new Products($logger, new Sanitizer()),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions(new Sanitizer())
        );

        return new CoreToolProvider(
            new LoadSkill($skills),
            new SearchProducts($backend, $serializer),
            new SearchCategories($backend, $serializer),
            new GetProductDetails($backend, $serializer, $fence),
            new GetCart($backend, $serializer, $fence),
            new AddToCart($cartWrite),
            new UpdateCartItem($cartWrite),
            new RemoveFromCart($cartWrite),
            new GetPreferences($backend, $fence),
            new GetOrders($backend, $serializer, $fence),
            new GetOrderStatus($backend, $serializer, $fence),
            new SearchPolicies($backend, $serializer, $fence),
            new GetFulfillmentOptions($backend, $serializer, $fence),
            new MemoryOff(),
            $skills,
            $presentation
        );
    }

    private function config(): AgentConfig
    {
        return new AgentConfig();
    }

    /**
     * Every fixed-shape 'type' => 'object' node (one with a 'properties' list) carries
     * additionalProperties false; an open string map (filters.attributes) is the one
     * legitimate exception and is not a fixed shape, so it is left alone.
     */
    private function assertEveryObjectForbidsAdditionalProperties(array $schema, string $path): void
    {
        if (($schema['type'] ?? null) === 'object' && isset($schema['properties'])) {
            $this->assertArrayHasKey('additionalProperties', $schema, $path . ' is missing additionalProperties');
            $this->assertFalse($schema['additionalProperties'], $path . ' allows additional properties');
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (is_array($propertySchema)) {
                    $this->assertEveryObjectForbidsAdditionalProperties($propertySchema, $path . '.' . $name);
                }
            }
        }
        if (($schema['type'] ?? null) === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            $this->assertEveryObjectForbidsAdditionalProperties($schema['items'], $path . '[]');
        }
    }

    public function testTwentyToolsInTheFixedOrder(): void
    {
        $expectedOrder = [
            'load_skill',
            'search_products',
            'search_categories',
            'get_product_details',
            'get_cart',
            'add_to_cart',
            'update_cart_item',
            'remove_from_cart',
            'get_preferences',
            'get_orders',
            'get_order_status',
            'search_policies',
            'get_fulfillment_options',
            'save_memory',
            'recall_memories',
            'present_products',
            'present_comparison',
            'present_order_status',
            'checkout',
            'present_suggestions',
        ];
        $definitions = $this->buildProvider()->getTools($this->config());
        $this->assertCount(20, $definitions);
        $this->assertSame($expectedOrder, array_map(static fn (Definition $d): string => $d->getName(), $definitions));
    }

    public function testAdditionalPropertiesFalseEverywhere(): void
    {
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $this->assertEveryObjectForbidsAdditionalProperties(
                $definition->getInputSchema(),
                $definition->getName()
            );
        }
    }

    public function testRequiredListsMatchTheToolTable(): void
    {
        $expectedRequired = [
            'load_skill' => ['skill_name'],
            'search_products' => [],
            'search_categories' => ['keywords'],
            'get_product_details' => ['product_id'],
            'get_cart' => null,
            'add_to_cart' => ['product_id'],
            'update_cart_item' => ['product_id', 'quantity'],
            'remove_from_cart' => ['product_id'],
            'get_preferences' => null,
            'get_orders' => null,
            'get_order_status' => ['order_id'],
            'search_policies' => ['query'],
            'get_fulfillment_options' => ['product_ids'],
            'save_memory' => ['key', 'value'],
            'recall_memories' => ['topic'],
            'present_products' => ['picks'],
            'present_comparison' => ['entries'],
            'present_order_status' => ['order_id', 'summary'],
            'checkout' => null,
            'present_suggestions' => ['suggestions'],
        ];
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $schema = $definition->getInputSchema();
            $expected = $expectedRequired[$definition->getName()];
            if ($expected === null) {
                $this->assertArrayNotHasKey('required', $schema, $definition->getName());
            } else {
                $this->assertSame($expected, $schema['required'], $definition->getName());
            }
        }
    }

    public function testKindAndTakesStatusMatchThePresentationSplit(): void
    {
        $presentationNames = [
            'present_products', 'present_comparison', 'present_order_status', 'checkout', 'present_suggestions',
        ];
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $isPresentation = in_array($definition->getName(), $presentationNames, true);
            $this->assertSame($isPresentation, $definition->getKind() === 'presentation', $definition->getName());
            $this->assertSame(!$isPresentation, $definition->takesStatus(), $definition->getName());
            $this->assertSame($isPresentation, $definition->getHandler() === null, $definition->getName());
        }
    }

    public function testRemembersProductsMatchesTheSpecifiedFour(): void
    {
        $expected = ['search_products', 'get_product_details', 'get_orders', 'get_order_status'];
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $this->assertSame(
                in_array($definition->getName(), $expected, true),
                $definition->remembersProducts(),
                $definition->getName()
            );
        }
    }

    public function testProductIdArgumentsMatchTheSpecifiedTools(): void
    {
        $expected = [
            'get_product_details' => [],
            'add_to_cart' => ['product_id'],
            'update_cart_item' => ['product_id'],
            'remove_from_cart' => ['product_id'],
            'get_fulfillment_options' => ['product_ids[]'],
        ];
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $this->assertSame(
                $expected[$definition->getName()] ?? [],
                $definition->getProductIdArguments(),
                $definition->getName()
            );
        }
    }

    public function testAddToCartOptionsSchemaAcceptsStringValuedCustomOptionSelections(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'add_to_cart') {
                continue;
            }
            $schema = $definition->getInputSchema();
            $this->assertArrayHasKey('options', $schema['properties']);
            $optionsSchema = $schema['properties']['options'];
            $this->assertSame('object', $optionsSchema['type']);
            $this->assertSame(['type' => 'string'], $optionsSchema['additionalProperties']);
            $this->assertNotContains('options', $schema['required']);
            $this->assertStringContainsString('custom_options', $definition->getDescription());
            return;
        }
        $this->fail('add_to_cart definition not found');
    }

    public function testSearchProductsLimitMaximumFollowsConfig(): void
    {
        $config = new AgentConfig(maxSearchResults: 5);
        $definitions = $this->buildProvider()->getTools($config);
        foreach ($definitions as $definition) {
            if ($definition->getName() === 'search_products') {
                $this->assertSame(5, $definition->getInputSchema()['properties']['limit']['maximum']);
                return;
            }
        }
        $this->fail('search_products definition not found');
    }

    public function testSearchCategoriesIsPresentWithKeywordsRequiredAndNoStatusOnProducts(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'search_categories') {
                continue;
            }
            $this->assertSame('read', $definition->getKind());
            $this->assertFalse($definition->remembersProducts());
            $this->assertSame(['keywords'], $definition->getInputSchema()['required']);
            $this->assertSame(20, $definition->getInputSchema()['properties']['limit']['maximum']);
            return;
        }
        $this->fail('search_categories definition not found');
    }

    public function testSearchProductsQueryIsOptionalAndDescribesTheCategoryIdAlternative(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'search_products') {
                continue;
            }
            $schema = $definition->getInputSchema();
            $this->assertSame([], $schema['required']);
            $this->assertStringContainsString('filters.category_id is required', $schema['properties']['query']['description']);
            return;
        }
        $this->fail('search_products definition not found');
    }

    public function testSearchProductsFiltersSchemaIncludesCategoryId(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'search_products') {
                continue;
            }
            $categoryIdSchema = $definition->getInputSchema()['properties']['filters']['properties']['category_id'];
            $this->assertSame('integer', $categoryIdSchema['type']);
            return;
        }
        $this->fail('search_products definition not found');
    }

    public function testSearchProductsFiltersSortEnumIncludesBestSellers(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'search_products') {
                continue;
            }
            $sortSchema = $definition->getInputSchema()['properties']['filters']['properties']['sort'];
            $this->assertContains('best_sellers', $sortSchema['enum']);
            return;
        }
        $this->fail('search_products definition not found');
    }

    public function testSearchProductsFiltersSchemaHasNoRatingOptions(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() !== 'search_products') {
                continue;
            }
            $filtersSchema = $definition->getInputSchema()['properties']['filters']['properties'];
            $this->assertArrayNotHasKey('min_rating', $filtersSchema);
            $this->assertNotContains('rating', $filtersSchema['sort']['enum']);
            return;
        }
        $this->fail('search_products definition not found');
    }

    public function testLoadSkillEnumListsInstalledSkillNamesSorted(): void
    {
        $definitions = $this->buildProvider()->getTools($this->config());
        foreach ($definitions as $definition) {
            if ($definition->getName() === 'load_skill') {
                $enum = $definition->getInputSchema()['properties']['skill_name']['enum'];
                $sorted = $enum;
                sort($sorted);
                $this->assertSame($sorted, $enum);
                $this->assertContains('search-discovery', $enum);
                return;
            }
        }
        $this->fail('load_skill definition not found');
    }

    public function testDescriptionsMatchTheReferenceRegistryWordForWord(): void
    {
        $expected = [
            'get_cart' => 'Current cart contents with quantities and subtotal.',
            'remove_from_cart' => 'Remove an item from the cart.',
            'search_policies' => "Search the store's own terms and help content: returns, shipping, "
                . 'warranties, membership, fees, and the buying guides. It covers the '
                . 'pages and blocks listed under Store facts.',
        ];
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            if (isset($expected[$definition->getName()])) {
                $this->assertSame($expected[$definition->getName()], $definition->getDescription());
            }
        }
    }

    public function testNoEmOrEnDashInAnyDescriptionOrSchema(): void
    {
        $encoded = json_encode(
            array_map(
                static fn (Definition $d): array => $d->apiDefinition(),
                $this->buildProvider()->getTools($this->config())
            )
        );
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString("\xE2\x80\x94", $encoded);
        $this->assertStringNotContainsString("\xE2\x80\x93", $encoded);
    }

    public function testApiDefinitionPropertiesAlwaysEncodeAsAJsonObject(): void
    {
        foreach ($this->buildProvider()->getTools($this->config()) as $definition) {
            $encoded = json_encode($definition->apiDefinition());
            $this->assertIsString($encoded);
            $this->assertStringContainsString('"properties":{', $encoded, $definition->getName());
            $this->assertStringNotContainsString('"properties":[', $encoded, $definition->getName());
        }
    }
}
