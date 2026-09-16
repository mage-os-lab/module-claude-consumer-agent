<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Block;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Block\ProductAsk;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ProductAskTest extends TestCase
{
    private function buildBlock(bool $enabled, ?Product $product): ProductAsk
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($enabled);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $catalogHelper = $this->createMock(CatalogHelper::class);
        $catalogHelper->method('getProduct')->willReturn($product);
        $block = $this->getMockBuilder(ProductAsk::class)
            ->setConstructorArgs([
                $context,
                new StoreConfig($scopeConfig, $storeManager),
                $catalogHelper,
                ['template' => 'MageOS_ClaudeConsumerAgent::product/ask.phtml'],
            ])
            ->onlyMethods(['fetchView', 'getTemplateFile'])
            ->getMock();
        $block->method('fetchView')->willReturn('rendered');

        return $block;
    }

    private function invokeToHtml(ProductAsk $block): string
    {
        $method = new ReflectionMethod(ProductAsk::class, '_toHtml');
        return (string)$method->invoke($block);
    }

    public function testRendersTemplateWhenEnabledWithACurrentProduct(): void
    {
        $this->assertSame('rendered', $this->invokeToHtml($this->buildBlock(true, $this->createMock(Product::class))));
    }

    public function testRendersNothingWithoutACurrentProduct(): void
    {
        $this->assertSame('', $this->invokeToHtml($this->buildBlock(true, null)));
    }

    public function testRendersNothingWhenTheAssistantIsDisabled(): void
    {
        $this->assertSame('', $this->invokeToHtml($this->buildBlock(false, $this->createMock(Product::class))));
    }

    public function testProductIdComesFromTheCurrentProduct(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn(42);

        $this->assertSame('42', $this->buildBlock(true, $product)->getProductId());
    }

    public function testProductIdIsEmptyWithoutACurrentProduct(): void
    {
        $this->assertSame('', $this->buildBlock(true, null)->getProductId());
    }
}
