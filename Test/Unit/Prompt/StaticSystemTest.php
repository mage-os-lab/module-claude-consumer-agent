<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Backend\CatalogMapProviderInterface;
use MageOS\AiShoppingAssistant\Api\Backend\StoreFactTitleResolverInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CatalogMap;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CoreFacts;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StoreFactsBlock;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

final class StaticSystemTest extends TestCase
{
    private function passthroughTitleResolver(): StoreFactTitleResolverInterface
    {
        $resolver = $this->createMock(StoreFactTitleResolverInterface::class);
        $resolver->method('resolve')->willReturnArgument(1);
        return $resolver;
    }

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

    private function buildInstance(
        ?CatalogMapProviderInterface $catalogMapProvider = null,
        ?ScopeConfigInterface $scopeConfig = null
    ): StaticSystem {
        $scopeConfig ??= $this->createMock(ScopeConfigInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        return new StaticSystem(
            $storeConfig,
            $this->realSkillRegistry(),
            $cache,
            new Json(),
            new Fence(new Sanitizer()),
            $catalogMapProvider ?? $this->createMock(CatalogMapProviderInterface::class),
            new CatalogMap(),
            new CoreFacts([]),
            new StoreFactsBlock(),
            $this->passthroughTitleResolver()
        );
    }

    private function assemble(AgentConfig $config, string $skillsIndex, string $mapText = '', string $storeFactsText = ''): string
    {
        $ref = new \ReflectionClass(StaticSystem::class);
        $method = $ref->getMethod('assemble');
        return $method->invoke($this->buildInstance(), $config, $skillsIndex, $mapText, $storeFactsText);
    }

    private function buildConfig(array $overrides = []): AgentConfig
    {
        $defaults = [
            'brandName' => 'ACME',
            'assistantName' => 'the shopping assistant',
            'brandVoice' => 'warm, concise, and plain about trade-offs',
            'enableCart' => true,
            'enableOrders' => true,
            'enablePolicies' => true,
            'enableFulfillment' => true,
            'contactUrl' => '',
            'contactLabel' => 'Contact us',
        ];
        $args = array_merge($defaults, $overrides);
        return new AgentConfig(
            brandName: $args['brandName'],
            assistantName: $args['assistantName'],
            brandVoice: $args['brandVoice'],
            enableCart: $args['enableCart'],
            enableOrders: $args['enableOrders'],
            enablePolicies: $args['enablePolicies'],
            enableFulfillment: $args['enableFulfillment'],
            contactUrl: $args['contactUrl'],
            contactLabel: $args['contactLabel']
        );
    }

    public function testTextIsByteIdenticalAcrossTwoCallsForTheSameConfig(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $config = $this->buildConfig();
        $this->assertSame($this->assemble($config, $index), $this->assemble($config, $index));
    }

    public function testNoEmOrEnDashInTheOutput(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringNotContainsString("\xE2\x80\x94", $text);
        $this->assertStringNotContainsString("\xE2\x80\x93", $text);
    }

    public function testCartSwitchChangesTheText(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $baseline = $this->assemble($this->buildConfig(['enableCart' => true]), $index);
        $withoutCart = $this->assemble($this->buildConfig(['enableCart' => false]), $index);
        $this->assertNotSame($baseline, $withoutCart);
        $this->assertStringContainsString('When the customer tells you to add, remove, buy, or stage', $baseline);
        $this->assertStringNotContainsString(
            'When the customer tells you to add, remove, buy, or stage',
            $withoutCart
        );
        $this->assertStringContainsString('This store has no a cart or checkout', $withoutCart);
    }

    public function testOrdersSwitchChangesTheText(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $baseline = $this->assemble($this->buildConfig(['enableOrders' => true]), $index);
        $withoutOrders = $this->assemble($this->buildConfig(['enableOrders' => false]), $index);
        $this->assertNotSame($baseline, $withoutOrders);
        $this->assertStringContainsString('Fill a request to buy something again from get_orders', $baseline);
        $this->assertStringNotContainsString(
            'Fill a request to buy something again from get_orders',
            $withoutOrders
        );
        $this->assertStringContainsString('This store has no order history or tracking', $withoutOrders);
    }

    public function testPoliciesSwitchChangesTheText(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $baseline = $this->assemble($this->buildConfig(['enablePolicies' => true]), $index);
        $withoutPolicies = $this->assemble($this->buildConfig(['enablePolicies' => false]), $index);
        $this->assertNotSame($baseline, $withoutPolicies);
        $this->assertStringContainsString("Answer questions about the store's terms", $baseline);
        $this->assertStringContainsString("an item the list does not name is unresolved, not allowed", $baseline);
        $this->assertStringNotContainsString("unresolved, not allowed", $withoutPolicies);
        $this->assertStringNotContainsString("Answer questions about the store's terms", $withoutPolicies);
        $this->assertStringContainsString("This store has no a lookup of the store's terms", $withoutPolicies);
    }

    public function testFulfillmentSwitchChangesTheText(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $baseline = $this->assemble($this->buildConfig(['enableFulfillment' => true]), $index);
        $withoutFulfillment = $this->assemble($this->buildConfig(['enableFulfillment' => false]), $index);
        $this->assertNotSame($baseline, $withoutFulfillment);
        $this->assertStringContainsString(
            'answer a delivery or pickup question from get_fulfillment_options',
            $baseline
        );
        $this->assertStringNotContainsString(
            'answer a delivery or pickup question from get_fulfillment_options',
            $withoutFulfillment
        );
        $this->assertStringContainsString('This store has no delivery or pickup options', $withoutFulfillment);
    }

    public function testBrandAndAssistantNameAppearInText(): void
    {
        $config = $this->buildConfig(['brandName' => 'ACME', 'assistantName' => 'Robin']);
        $text = $this->assemble($config, $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString('ACME', $text);
        $this->assertStringContainsString('Robin', $text);
    }

    public function testCustomOptionsRuleIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'For a product whose record lists custom_options, ask the customer to choose each '
                . 'required option from the listed values (chips when four or fewer, else a short '
                . 'list), then call add_to_cart with options mapping option title to value title. '
                . 'Never invent option values.',
            $text
        );
    }

    public function testNamedOptionValuesRuleIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            "already named option values, pass them in the pick's option_values so the card shows "
                . 'only the matching variants.',
            $text
        );
    }

    public function testShortlistFamilyDoesNotExpandRuleIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'Presenting the family is enough when it is the only pick in the call: the card expands it '
                . 'into its variants. When the call presents it alongside other products, keep it to one '
                . 'card there too: name its available options in the reason instead of expanding into '
                . 'variants, so it does not crowd out the other products; the customer can ask about that '
                . 'one product afterward to see them.',
            $text
        );
    }

    public function testOriginalPriceGroundingRuleIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'A record with an original_price is on sale: the price field is what the customer '
                . 'pays now and original_price is what it was, so a discount is the difference '
                . 'between them; a record without original_price is not on sale, and you must not '
                . 'infer a discount from anything else.',
            $text
        );
    }

    public function testUnavailableSpecRuleBansOtherSellersAndNamesTheStoreContact(): void
    {
        $config = $this->buildConfig([
            'contactUrl' => '/contact/',
            'contactLabel' => 'Talk to a design consultant',
        ]);
        $text = $this->assemble($config, $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'When something is unavailable or unknown, say what the record does say, then say '
                . "plainly it does not carry the rest. Do not name a manufacturer, a brand's own "
                . 'site, a marketplace, a competitor, or any other retailer as the place to find '
                . 'it. When the record leaves nothing else to offer, point the customer to Talk '
                . 'to a design consultant (/contact/) instead.',
            $text
        );
    }

    public function testUnavailableSpecRuleFallsBackToTheContactLabelWhenNoUrlIsConfigured(): void
    {
        $config = $this->buildConfig(['contactUrl' => '', 'contactLabel' => 'Contact us']);
        $text = $this->assemble($config, $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'point the customer to Contact us instead.',
            $text
        );
    }

    public function testCurrentPageCategoryRuleIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            "The Session context block's current_page says where the customer is. On a category "
                . 'page, "this category", "here", and "these" mean current_page.category_id: pass it '
                . 'as filters.category_id, with no query when they ask for everything, the best '
                . 'sellers, or the cheapest in it. On a product page, "this", "this one", and "it" '
                . 'mean current_page.product_id. On a search page, current_page.query is what they '
                . 'searched for.',
            $text
        );
    }

    public function testPageChangeNoteSentenceIsPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'A user message that opens with a [Page: ...] line reports a navigation since the '
                . 'last message; "this", "this one", and "here" then mean the page it names, and '
                . '"the previous one" means the page it says they came from, unless the customer\'s '
                . 'words clearly point at something else.',
            $text
        );
    }

    public function testMemoryOffLineIsAlwaysPresent(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'This store does not remember facts between conversations; say so if asked.',
            $text
        );
    }

    public function testSkillsIndexIsIncluded(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString('search-discovery', $text);
    }

    public function testCacheMissAssemblesAndSavesUnderTheAiagentTagWithADayLifetime(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $skills = $this->realSkillRegistry();

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $savedText = null;
        $savedTags = null;
        $savedLifetime = null;
        $cache->expects($this->once())->method('save')->willReturnCallback(
            function (string $data, string $id, array $tags, int $lifetime) use (&$savedText, &$savedTags, &$savedLifetime): bool {
                $savedText = $data;
                $savedTags = $tags;
                $savedLifetime = $lifetime;
                return true;
            }
        );

        $staticSystem = new StaticSystem(
            $storeConfig,
            $skills,
            $cache,
            new Json(),
            new Fence(new Sanitizer()),
            $this->createMock(CatalogMapProviderInterface::class),
            new CatalogMap(),
            new CoreFacts([]),
            new StoreFactsBlock(),
            $this->passthroughTitleResolver()
        );
        $text = $staticSystem->text(1);

        $this->assertSame($savedText, $text);
        $this->assertSame(['AIAGENT', Config::CACHE_TAG], $savedTags);
        $this->assertSame(86400, $savedLifetime);
    }

    public function testCacheHitReturnsTheCachedTextWithoutReassembling(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $skills = $this->realSkillRegistry();

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('previously cached text');
        $cache->expects($this->never())->method('save');

        $staticSystem = new StaticSystem(
            $storeConfig,
            $skills,
            $cache,
            new Json(),
            new Fence(new Sanitizer()),
            $this->createMock(CatalogMapProviderInterface::class),
            new CatalogMap(),
            new CoreFacts([]),
            new StoreFactsBlock(),
            $this->passthroughTitleResolver()
        );
        $this->assertSame('previously cached text', $staticSystem->text(1));
    }

    public function testCatalogMapSectionIsPresentWhenMapTextIsGiven(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $config = $this->buildConfig();
        $text = $this->assemble($config, $index, '- Seating [5]');

        $this->assertStringContainsString('# Catalog map', $text);
        $this->assertStringContainsString('- Seating [5]', $text);
        $this->assertStringContainsString('search_categories finds it by keyword', $text);
    }

    public function testCatalogMapSectionIsAbsentWhenMapTextIsEmpty(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $config = $this->buildConfig();
        $text = $this->assemble($config, $index, '');

        $this->assertStringNotContainsString('# Catalog map', $text);
    }

    public function testCatalogMapProviderIsNotCalledWhenDepthIsZero(): void
    {
        $provider = $this->createMock(CatalogMapProviderInterface::class);
        $provider->expects($this->never())->method('map');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/catalog_map_depth' ? '0' : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $staticSystem = $this->buildInstance($provider, $scopeConfig);
        $text = $staticSystem->text(1);

        $this->assertStringNotContainsString('# Catalog map', $text);
    }

    public function testCatalogMapSectionIsAbsentWhenProviderReturnsNothing(): void
    {
        $provider = $this->createMock(CatalogMapProviderInterface::class);
        $provider->method('map')->willReturn([]);

        $staticSystem = $this->buildInstance($provider);
        $text = $staticSystem->text(1);

        $this->assertStringNotContainsString('# Catalog map', $text);
    }

    public function testCatalogMapSectionIsPresentWhenProviderReturnsNodes(): void
    {
        $provider = $this->createMock(CatalogMapProviderInterface::class);
        $provider->method('map')->willReturn([['id' => 5, 'name' => 'Seating', 'children' => []]]);

        $staticSystem = $this->buildInstance($provider);
        $text = $staticSystem->text(1);

        $this->assertStringContainsString('# Catalog map', $text);
        $this->assertStringContainsString('Seating [5]', $text);
    }

    public function testStoreFactsSectionIsPresentWhenStoreFactsTextIsGiven(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $config = $this->buildConfig();
        $text = $this->assemble($config, $index, '', "# Store facts\n\n- Price match: We match any price.");

        $this->assertStringContainsString('# Store facts', $text);
        $this->assertStringContainsString('Price match: We match any price.', $text);
    }

    public function testStoreFactsSectionIsAbsentWhenStoreFactsTextIsEmpty(): void
    {
        $index = $this->realSkillRegistry()->indexBlock();
        $config = $this->buildConfig();
        $text = $this->assemble($config, $index, '', '');

        $this->assertStringNotContainsString('# Store facts', $text);
    }

    public function testChipRuleNamesTheStoreFactsListForServiceChips(): void
    {
        $text = $this->assemble($this->buildConfig(), $this->realSkillRegistry()->indexBlock());
        $this->assertStringContainsString(
            'A chip that names a store service (wrapping, a gift message, pickup, financing, '
                . 'returns, warranty, a price match) comes from the Store facts list or a '
                . 'search_policies result.',
            $text
        );
    }

    public function testStoreFactsSectionAppearsWhenConfiguredThroughStoreConfig(): void
    {
        $facts = [
            '_1' => ['topic' => 'Gift wrapping', 'keywords' => 'gift wrap', 'source' => 'not_offered', 'value' => ''],
            '_2' => ['topic' => 'Price match', 'keywords' => 'price match', 'source' => 'text', 'value' => 'We match any price.'],
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($facts): ?string {
                if ($path === 'ai_integration/aiagent/content/store_facts') {
                    return (string)json_encode($facts);
                }
                if ($path === 'ai_integration/aiagent/content/include_core_facts') {
                    return '0';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $staticSystem = $this->buildInstance(null, $scopeConfig);
        $text = $staticSystem->text(1);

        $this->assertStringContainsString('# Store facts', $text);
        $this->assertStringContainsString('Price match', $text);
        $this->assertStringContainsString('We match any price.', $text);
        $this->assertStringContainsString('Not offered here: gift wrapping.', $text);
        $this->assertStringContainsString(
            'A row that carries its answer here is complete: answer from it without a tool call.',
            $text
        );
    }
}
