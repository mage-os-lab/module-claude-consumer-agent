<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use MageOS\AiShoppingAssistant\Api\Backend\ProductImageUrlInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreOptions;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\HelperImageUrl;
use MageOS\AiShoppingAssistant\Model\Backend\ProductMapper;
use MageOS\AiShoppingAssistant\Model\Backend\Salability;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProductMapperTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('session-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeManager(string $currency = 'USD'): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($currency);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function productImageUrl(string $url = 'https://example.test/img.jpg'): ProductImageUrlInterface&MockObject
    {
        $productImageUrl = $this->createMock(ProductImageUrlInterface::class);
        $productImageUrl->method('forProduct')->with($this->anything(), 'category_page_grid', $this->anything())
            ->willReturn($url);
        return $productImageUrl;
    }

    private function helperImageUrl(string $url = 'https://example.test/placeholder.jpg'): HelperImageUrl
    {
        $imageHelper = $this->createMock(\Magento\Catalog\Helper\Image::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn($url);
        return new HelperImageUrl($imageHelper);
    }

    private function salability(bool $result): Salability
    {
        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->method('execute')->willReturn($result);

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);

        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [IsProductSalableInterface::class, $isProductSalable],
        ]);

        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');
        $store = $this->createMock(Store::class);
        $store->method('getWebsite')->willReturn($website);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);

        return new Salability($objectManager, $stockRegistry, $storeManager);
    }

    private function priceInfo(float $value): PriceInfoInterface&MockObject
    {
        $price = $this->createMock(PriceInterface::class);
        $price->method('getValue')->willReturn($value);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);
        return $priceInfo;
    }

    private function salePriceInfo(float $regularValue, float $finalValue): PriceInfoInterface&MockObject
    {
        $regularPrice = $this->createMock(PriceInterface::class);
        $regularPrice->method('getValue')->willReturn($regularValue);
        $finalPrice = $this->createMock(PriceInterface::class);
        $finalPrice->method('getValue')->willReturn($finalValue);

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturnMap([
            [RegularPrice::PRICE_CODE, $regularPrice],
            [FinalPrice::PRICE_CODE, $finalPrice],
        ]);
        return $priceInfo;
    }

    private function product(): MagentoProduct&MockObject
    {
        return $this->createMock(MagentoProduct::class);
    }

    private function urlFinder(array $rewrites = []): UrlFinderInterface&MockObject
    {
        $urlFinder = $this->createMock(UrlFinderInterface::class);
        $urlFinder->method('findAllByData')->willReturn($rewrites);
        return $urlFinder;
    }

    public function testMapsSimpleProduct(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(101);
        $product->method('getName')->willReturn('Widget');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(19.99));
        $product->method('getProductUrl')->willReturn('https://example.test/widget');
        $product->method('getData')->willReturnCallback(
            static fn (string $key = ''): ?string => $key === 'short_description' ? '<p>Nice   and <b>tidy</b></p>' : null
        );

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame('101', $result->getProductId());
        $this->assertSame('Widget', $result->getTitle());
        $this->assertSame(19.99, $result->getPrice());
        $this->assertSame('USD', $result->getCurrency());
        $this->assertSame('https://example.test/widget', $result->getUrl());
        $this->assertSame('https://example.test/img.jpg', $result->getImageUrl());
        $this->assertTrue($result->isInStock());
        $this->assertSame('Nice and tidy', $result->getShortDescription());
        $this->assertSame([], $result->getOptions());
        $this->assertFalse($result->hasRequiredCustomOptions());
    }

    public function testMapsConfigurableProductOptions(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(55);
        $product->method('getName')->willReturn('Shirt');
        $product->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $product->method('getPriceInfo')->willReturn($this->priceInfo(29.0));
        $product->method('getProductUrl')->willReturn('https://example.test/shirt');

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([
            [
                'attribute_id' => '93',
                'attribute_code' => 'color',
                'label' => 'Color',
                'options' => [
                    ['value' => '10', 'label' => 'Red'],
                    ['value' => '11', 'label' => 'Blue'],
                ],
            ],
        ]);
        $product->method('getTypeInstance')->willReturn($typeInstance);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(['Color' => ['Red', 'Blue']], $result->getOptions());
        $this->assertSame([], $result->getAttributes());
        $this->assertFalse($result->hasRequiredCustomOptions());
    }

    public function testFlagsRequiredCustomOption(): void
    {
        $option = $this->createMock(ProductCustomOptionInterface::class);
        $option->method('getIsRequire')->willReturn(true);

        $product = $this->product();
        $product->method('getId')->willReturn(9);
        $product->method('getName')->willReturn('Engraved Item');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(5.0));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getOptions')->willReturn([$option]);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertTrue($result->hasRequiredCustomOptions());
    }

    public function testCustomOptionsFeedAttributesAsValueCounts(): void
    {
        $requiredOption = $this->createMock(ProductCustomOptionInterface::class);
        $requiredOption->method('getTitle')->willReturn('Engraving Text');
        $requiredOption->method('getIsRequire')->willReturn(true);
        $requiredOption->method('getValues')->willReturn([]);

        $selectOption = $this->createMock(ProductCustomOptionInterface::class);
        $selectOption->method('getTitle')->willReturn('Gift Wrap');
        $selectOption->method('getIsRequire')->willReturn(false);
        $selectOption->method('getValues')->willReturn([
            $this->createMock(ProductCustomOptionValuesInterface::class),
            $this->createMock(ProductCustomOptionValuesInterface::class),
        ]);

        $product = $this->product();
        $product->method('getId')->willReturn(12);
        $product->method('getName')->willReturn('Trophy');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(50.0));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getOptions')->willReturn([$requiredOption, $selectOption]);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertTrue($result->hasRequiredCustomOptions());
        $this->assertSame(
            ['Engraving Text' => '0 values', 'Gift Wrap' => '2 values'],
            $result->getAttributes()
        );
    }

    public function testCustomOptionsListCarriesOptionAndValueDetail(): void
    {
        $small = $this->createMock(ProductCustomOptionValuesInterface::class);
        $small->method('getOptionTypeId')->willReturn(21);
        $small->method('getTitle')->willReturn('Small');
        $small->method('getPrice')->willReturn(0.0);
        $small->method('getPriceType')->willReturn('fixed');

        $large = $this->createMock(ProductCustomOptionValuesInterface::class);
        $large->method('getOptionTypeId')->willReturn(22);
        $large->method('getTitle')->willReturn('Large');
        $large->method('getPrice')->willReturn(5.0);
        $large->method('getPriceType')->willReturn('fixed');

        $sizeOption = $this->createMock(ProductCustomOptionInterface::class);
        $sizeOption->method('getOptionId')->willReturn(7);
        $sizeOption->method('getTitle')->willReturn('Size');
        $sizeOption->method('getType')->willReturn('drop_down');
        $sizeOption->method('getIsRequire')->willReturn(true);
        $sizeOption->method('getValues')->willReturn([$small, $large]);

        $product = $this->product();
        $product->method('getId')->willReturn(13);
        $product->method('getName')->willReturn('Espresso Maker');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(99.0));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getOptions')->willReturn([$sizeOption]);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(
            [[
                'option_id' => 7,
                'title' => 'Size',
                'type' => 'drop_down',
                'required' => true,
                'values' => [
                    ['value_id' => 21, 'title' => 'Small', 'price' => 0.0, 'price_type' => 'fixed'],
                    ['value_id' => 22, 'title' => 'Large', 'price' => 5.0, 'price_type' => 'fixed'],
                ],
            ]],
            $result->getCustomOptions()
        );
    }

    public function testResolvesBrandAndOutOfStock(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(3);
        $product->method('getName')->willReturn('Lamp');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(15.0));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getAttributeText')->willReturn('Acme Co');

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(false),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame('Acme Co', $result->getBrand());
        $this->assertFalse($result->isInStock());
    }

    public function testPreloadRequestPathsFetchesAllRewritesInOneBulkCall(): void
    {
        $productOne = $this->product();
        $productOne->method('getId')->willReturn(10);
        $productTwo = $this->product();
        $productTwo->method('getId')->willReturn(11);

        $urlFinder = $this->createMock(UrlFinderInterface::class);
        $urlFinder->expects($this->once())->method('findAllByData')->with([
            UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            UrlRewrite::ENTITY_ID => [10, 11],
            UrlRewrite::STORE_ID => 1,
            UrlRewrite::REDIRECT_TYPE => 0,
        ])->willReturn([]);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $urlFinder
        );

        $mapper->preloadRequestPaths([$productOne, $productTwo], 1);
    }

    public function testToProductUsesThePrecomputedSalabilityMapWithoutCallingSalability(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(44);
        $product->method('getName')->willReturn('Boots');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(40.0));
        $product->method('getProductUrl')->willReturn(null);

        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->expects($this->never())->method('execute');
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [IsProductSalableInterface::class, $isProductSalable],
        ]);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->expects($this->never())->method('getStockStatus');
        $salability = new Salability($objectManager, $stockRegistry, $this->storeManager());

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $salability,
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context(), false);

        $this->assertFalse($result->isInStock());
    }

    public function testFallsBackToTheHelperImageWhenTheProviderHasNoUrl(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(77);
        $product->method('getName')->willReturn('No Selection');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(10.0));
        $product->method('getProductUrl')->willReturn(null);

        $productImageUrl = $this->createMock(ProductImageUrlInterface::class);
        $productImageUrl->method('forProduct')->willReturn(null);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $productImageUrl,
            $this->helperImageUrl('https://example.test/placeholder.jpg'),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame('https://example.test/placeholder.jpg', $result->getImageUrl());
    }

    public function testMarkedDownProductCarriesTheRegularPriceAsOriginalPrice(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(201);
        $product->method('getName')->willReturn('Clearance Lamp');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->salePriceInfo(140.5, 119.4));
        $product->method('getProductUrl')->willReturn(null);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(119.4, $result->getPrice());
        $this->assertSame(140.5, $result->getOriginalPrice());
    }

    public function testFullPriceProductHasNoOriginalPrice(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(202);
        $product->method('getName')->willReturn('Full Price Lamp');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->salePriceInfo(100.0, 100.0));
        $product->method('getProductUrl')->willReturn(null);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(100.0, $result->getPrice());
        $this->assertNull($result->getOriginalPrice());
    }

    public function testAMarkdownUnderOneCentIsNotTreatedAsAnOriginalPrice(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(203);
        $product->method('getName')->willReturn('Rounded Lamp');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->salePriceInfo(100.005, 100.0));
        $product->method('getProductUrl')->willReturn(null);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertNull($result->getOriginalPrice());
    }

    public function testAPriceInfoExceptionFallsBackToAZeroPriceAndNoOriginalPrice(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(204);
        $product->method('getName')->willReturn('No Price Info');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willThrowException(new \RuntimeException('no price info'));
        $product->method('getProductUrl')->willReturn(null);

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(0.0, $result->getPrice());
        $this->assertNull($result->getOriginalPrice());
    }

    public function testShortDescriptionCollapsesDoubledQuotesAndApostrophes(): void
    {
        $product = $this->product();
        $product->method('getId')->willReturn(205);
        $product->method('getName')->willReturn('Bistro Chair');
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo(45.0));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = ''): ?string => $key === 'short_description'
                ? 'the ""slum of legs"" found under chairs, a woman\'\'s favourite'
                : null
        );

        $mapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );

        $result = $mapper->toProduct($product, $this->context());

        $this->assertSame(
            'the "slum of legs" found under chairs, a woman\'s favourite',
            $result->getShortDescription()
        );
    }
}
