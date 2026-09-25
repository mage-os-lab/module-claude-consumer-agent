<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Surface\PageDetector;
use MageOS\AiShoppingAssistant\Model\Surface\Resolver;
use MageOS\AiShoppingAssistant\ViewModel\Assistant;
use PHPUnit\Framework\TestCase;

final class AssistantTest extends TestCase
{
    private function buildAssistant(string $fullActionName = 'cms_index_index', array $configValues = []): Assistant
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $configValues[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => (bool)($configValues[$path] ?? false)
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $resolver = new Resolver($storeConfig, $scopeConfig);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn($fullActionName);
        $pageDetector = new PageDetector(
            $request,
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(ProductRepositoryInterface::class),
            $storeManager
        );

        return new Assistant($storeConfig, $resolver, $url, $storeManager, $pageDetector);
    }

    public function testSnapshotContainsExpectedTopLevelKeys(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertSame(
            [
                'urls',
                'surface',
                'page',
                'headerIconView',
                'keepOpen',
                'streaming',
                'firstByteThreshold',
                'assistantName',
                'greeting',
                'starters',
                'showAiLabel',
                'contact',
                'maxMessageLength',
                'cards',
                'i18n',
            ],
            array_keys($snapshot)
        );
    }

    public function testSnapshotKeepOpenIsTrueWhenTheConfigValueIsOne(): void
    {
        $snapshot = $this->buildAssistant('cms_index_index', ['ai_integration/aiagent/general/keep_open' => '1'])
            ->snapshot();

        $this->assertTrue($snapshot['keepOpen']);
    }

    public function testSnapshotKeepOpenIsFalseWhenTheConfigValueIsZero(): void
    {
        $snapshot = $this->buildAssistant('cms_index_index', ['ai_integration/aiagent/general/keep_open' => '0'])
            ->snapshot();

        $this->assertFalse($snapshot['keepOpen']);
    }

    public function testSnapshotCardsDefaultsAllShown(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertSame(['products'], array_keys($snapshot['cards']));
        $this->assertSame(
            [
                'image' => true,
                'price' => true,
                'description' => true,
                'stock' => true,
                'addToCart' => true,
                'reason' => true,
            ],
            $snapshot['cards']['products']
        );
    }

    public function testSnapshotUrlsContainTheFourRoutes(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertSame(['start', 'turn', 'transcript', 'reset'], array_keys($snapshot['urls']));
        $this->assertSame('/aiagent/session/start', $snapshot['urls']['start']);
        $this->assertSame('/aiagent/turn/index', $snapshot['urls']['turn']);
        $this->assertSame('/aiagent/session/transcript', $snapshot['urls']['transcript']);
        $this->assertSame('/aiagent/session/reset', $snapshot['urls']['reset']);
    }

    public function testSnapshotPageIsBuiltFromThePageDetector(): void
    {
        $snapshot = $this->buildAssistant('catalog_category_view')->snapshot();

        $this->assertSame(
            ['page_type', 'product_id', 'product_name', 'query', 'category_id', 'category_name'],
            array_keys($snapshot['page'])
        );
        $this->assertSame('category', $snapshot['page']['page_type']);
    }

    public function testSnapshotContactShapeAndDefaults(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertSame(['url', 'label'], array_keys($snapshot['contact']));
        $this->assertSame('', $snapshot['contact']['url']);
        $this->assertSame('Contact us', $snapshot['contact']['label']);
    }

    public function testSnapshotI18nShapeAndDefaults(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertSame(
            [
                'stillWorking',
                'reloadPage',
                'interrupted',
                'timedOut',
                'cartItems',
                'cartCount',
                'cartEmpty',
                'placeholder',
                'placeholderReply',
                'aiAssistant',
                'needPerson',
                'productChips',
                'products',
                'compare',
                'priceDifference',
                'fulfillment',
                'orderStatus',
            ],
            array_keys($snapshot['i18n'])
        );
        $this->assertCount(3, $snapshot['i18n']['productChips']);
        $this->assertSame(
            ['processing', 'shipped', 'delivered', 'delayed', 'return_initiated', 'cancelled', 'refunded', 'unknown'],
            array_keys($snapshot['i18n']['orderStatus'])
        );
    }

    public function testSnapshotCarriesNoCustomerIdentifyingData(): void
    {
        $snapshot = $this->buildAssistant()->snapshot();

        $this->assertArrayNotHasKey('customerId', $snapshot);
        $this->assertArrayNotHasKey('customer_id', $snapshot);
        $this->assertArrayNotHasKey('quoteId', $snapshot);
        $this->assertArrayNotHasKey('sessionId', $snapshot);
        $this->assertArrayNotHasKey('email', $snapshot);
    }

    public function testNameGreetingStartersAiLabelAndContactHelpers(): void
    {
        $assistant = $this->buildAssistant();

        $this->assertSame('the shopping assistant', $assistant->name());
        $this->assertSame('Hi. Tell me what you are looking for and I will find it.', $assistant->greeting());
        $this->assertCount(5, $assistant->starters());
        $this->assertSame('AI', $assistant->aiLabelText());
        $this->assertSame('', $assistant->contactUrl());
        $this->assertSame('Contact us', $assistant->contactLabel());
    }
}
