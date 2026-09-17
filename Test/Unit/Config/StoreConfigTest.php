<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class StoreConfigTest extends TestCase
{
    private function buildStoreConfig(
        ScopeConfigInterface&MockObject $scopeConfig,
        string $storeName = '',
        string $websiteName = ''
    ): StoreConfig {
        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getName')->willReturn($websiteName);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn($storeName);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    public function testDefaultsWhenValuesAreNull(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);
        $defaults = new AgentConfig();

        $this->assertSame($defaults->modelId, $config->modelId);
        $this->assertSame($defaults->maxTokens, $config->maxTokens);
        $this->assertSame($defaults->thinkingEffort, $config->thinkingEffort);
        $this->assertSame($defaults->brandName, $config->brandName);
        $this->assertSame($defaults->starters, $config->starters);
        $this->assertSame($defaults->policyPages, $config->policyPages);
        $this->assertSame($defaults->allowedCategories, $config->allowedCategories);
        $this->assertSame($defaults->catalogMapDepth, $config->catalogMapDepth);
        $this->assertSame($defaults->catalogMapRoots, $config->catalogMapRoots);
        $this->assertSame($defaults->catalogMapMaxChars, $config->catalogMapMaxChars);
        $this->assertSame($defaults->storeFacts, $config->storeFacts);
        $this->assertSame($defaults->includeCoreFacts, $config->includeCoreFacts);
        $this->assertSame($defaults->concurrentTurns, $config->concurrentTurns);
        $this->assertSame($defaults->retentionDays, $config->retentionDays);
        $this->assertSame($defaults->showAiLabel, $config->showAiLabel);
        $this->assertSame($defaults->enabled, $config->enabled);
        $this->assertSame($defaults->keepOpen, $config->keepOpen);
        $this->assertSame($defaults->policyIntentTerms, $config->policyIntentTerms);
        $this->assertSame($defaults->orderIntentTerms, $config->orderIntentTerms);
        $this->assertSame($defaults->productCard, $config->productCard);
    }

    public function testProductCardFlagsReadIndividually(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/cards/products_price') {
                    return '0';
                }
                if ($path === 'ai_integration/aiagent/cards/products_reason') {
                    return '0';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/cards/products_price') {
                    return false;
                }
                if ($path === 'ai_integration/aiagent/cards/products_reason') {
                    return false;
                }
                return true;
            }
        );
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(
            [
                'image' => true,
                'price' => false,
                'description' => true,
                'stock' => true,
                'addToCart' => true,
                'reason' => false,
            ],
            $config->productCard
        );
    }

    public function testLineSplittingTrimsDropsEmptyAndDeduplicates(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/voice/starters') {
                    return "  Find a gift  \n\nFind a gift\nWhat is new?\n";
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(['Find a gift', 'What is new?'], $config->starters);
    }

    public function testMultiselectFieldsSplitOnCommas(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/content/policy_pages') {
                    return 'returns, shipping ,returns';
                }
                if ($path === 'ai_integration/aiagent/content/allowed_categories') {
                    return '12, 34,56';
                }
                if ($path === 'ai_integration/aiagent/content/catalog_map_roots') {
                    return '175,176';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(['returns', 'shipping'], $config->policyPages);
        $this->assertSame([12, 34, 56], $config->allowedCategories);
        $this->assertSame([175, 176], $config->catalogMapRoots);
    }

    public function testCatalogMapFieldsAreReadFromConfig(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/content/catalog_map_depth') {
                    return '3';
                }
                if ($path === 'ai_integration/aiagent/content/catalog_map_max_chars') {
                    return '8000';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(3, $config->catalogMapDepth);
        $this->assertSame(8000, $config->catalogMapMaxChars);
    }

    public function testBrandNameFallsBackToStoreNameWhenConfigValueEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig, 'My Store');

        $config = $storeConfig->agent(1);

        $this->assertSame('My Store', $config->brandName);
    }

    public function testBrandNameFallsBackToStoreInformationNameWhenConfigValueEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'general/store_information/name') {
                    return 'Store Information Name';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig, 'My Store', 'My Website');

        $config = $storeConfig->agent(1);

        $this->assertSame('Store Information Name', $config->brandName);
    }

    public function testBrandNameFallsBackToWebsiteNameWhenStoreInformationNameEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig, 'My Store', 'My Website');

        $config = $storeConfig->agent(1);

        $this->assertSame('My Website', $config->brandName);
    }

    public function testBrandNameUsesConfiguredValueWhenPresent(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/voice/brand_name') {
                    return 'Configured Brand';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig, 'My Store');

        $config = $storeConfig->agent(1);

        $this->assertSame('Configured Brand', $config->brandName);
    }

    public function testIntsAreCast(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/model/max_tokens') {
                    return '4096';
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(4096, $config->maxTokens);
        $this->assertIsInt($config->maxTokens);
    }

    public function testAgentIsMemoizedPerStoreId(): void
    {
        $calls = 0;
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function () use (&$calls) {
                $calls++;
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $configFirst = $storeConfig->agent(1);
        $callsAfterFirst = $calls;
        $configSecond = $storeConfig->agent(1);

        $this->assertSame($configFirst, $configSecond);
        $this->assertSame($callsAfterFirst, $calls);
    }

    public function testIsEnabledReadsGeneralEnabledFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) {
                return $path === 'ai_integration/aiagent/general/enabled';
            }
        );
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertTrue($storeConfig->isEnabled(1));
    }

    public function testApiKeyReturnsConfiguredValueTrimmed(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'ai_integration/aiagent/model/api_key') {
                    return '  configured-key  ';
                }
                return null;
            }
        );
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertSame('configured-key', $storeConfig->apiKey(1));
    }

    public function testStoreFactsRowsAreParsedKeywordsLowercasedAndTrimmed(): void
    {
        $rows = [
            '_1' => ['topic' => ' Price match ', 'keywords' => ' Price Match, PRICE GUARANTEE ,price match', 'source' => 'text', 'value' => 'We match prices.'],
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/store_facts' ? json_encode($rows) : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame(
            [
                [
                    'topic' => 'Price match',
                    'keywords' => ['price match', 'price guarantee'],
                    'source' => 'text',
                    'value' => 'We match prices.',
                ],
            ],
            $config->storeFacts
        );
    }

    public function testStoreFactsRowWithAnEmptyTopicIsSkipped(): void
    {
        $rows = [
            '_1' => ['topic' => '   ', 'keywords' => 'anything', 'source' => 'text', 'value' => 'x'],
            '_2' => ['topic' => 'Returns', 'keywords' => '', 'source' => 'cms_page', 'value' => 'returns'],
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/store_facts' ? json_encode($rows) : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertCount(1, $config->storeFacts);
        $this->assertSame('Returns', $config->storeFacts[0]['topic']);
    }

    public function testStoreFactsRowWithAnUnknownSourceFallsBackToText(): void
    {
        $rows = [
            '_1' => ['topic' => 'Mystery', 'keywords' => '', 'source' => 'video', 'value' => 'unused'],
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/store_facts' ? json_encode($rows) : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $config = $storeConfig->agent(1);

        $this->assertSame('text', $config->storeFacts[0]['source']);
    }

    public function testEmptyOrMissingStoreFactsValueYieldsAnEmptyList(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertSame([], $storeConfig->agent(1)->storeFacts);
    }

    public function testEnablePoliciesIsTrueWhenAStoreFactUsesACmsPageOrBlock(): void
    {
        $rows = [
            '_1' => ['topic' => 'Care', 'keywords' => 'care', 'source' => 'cms_block', 'value' => 'care-guide'],
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/store_facts' ? json_encode($rows) : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertTrue($storeConfig->agent(1)->enablePolicies);
    }

    public function testIncludeCoreFactsReadsTheConfiguredFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/content/include_core_facts' ? '0' : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertFalse($storeConfig->agent(1)->includeCoreFacts);
    }

    public function testKeepOpenReadsTheConfiguredFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === 'ai_integration/aiagent/general/keep_open' ? '0' : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertFalse($storeConfig->agent(1)->keepOpen);
    }

    public function testApiKeyReturnsEmptyStringWhenValueIsNullOrEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertSame('', $storeConfig->apiKey(1));

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn('');
        $storeConfig = $this->buildStoreConfig($scopeConfig);

        $this->assertSame('', $storeConfig->apiKey(1));
    }
}
