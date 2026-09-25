<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Block;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Block\Surface;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Surface\PageDetector;
use MageOS\AiShoppingAssistant\Model\Surface\Resolver;
use MageOS\AiShoppingAssistant\ViewModel\Assistant;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SurfaceTest extends TestCase
{
    private function buildBlock(bool $enabled, string $surfaceMode, string $surfaceArgument): Surface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($surfaceMode) {
                return $path === 'ai_integration/aiagent/general/surface_mode' ? $surfaceMode : null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($enabled) {
                return $path === 'ai_integration/aiagent/general/enabled' ? $enabled : false;
            }
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $resolver = new Resolver($storeConfig, $scopeConfig);
        $url = $this->createMock(UrlInterface::class);
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn('cms_index_index');
        $pageDetector = new PageDetector(
            $request,
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(ProductRepositoryInterface::class),
            $storeManager
        );
        $assistant = new Assistant($storeConfig, $resolver, $url, $storeManager, $pageDetector);

        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn(false);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);
        $context->method('getLayout')->willReturn($layout);

        return new Surface($context, $resolver, $storeConfig, $assistant, ['surface' => $surfaceArgument]);
    }

    private function invokeToHtml(Surface $block): string
    {
        $method = new ReflectionMethod(Surface::class, '_toHtml');
        return (string)$method->invoke($block);
    }

    public function testRendersEmptyWhenModuleDisabled(): void
    {
        $block = $this->buildBlock(false, 'auto', 'side_cart');
        $this->assertSame('', $this->invokeToHtml($block));
    }

    public function testRendersEmptyWhenModuleDisabledForOverlaySurfaceToo(): void
    {
        $block = $this->buildBlock(false, 'auto', 'overlay');
        $this->assertSame('', $this->invokeToHtml($block));
    }

    public function testSideCartHiddenWhenResolverReturnsOverlay(): void
    {
        $block = $this->buildBlock(true, 'overlay', 'side_cart');
        $this->assertSame('', $this->invokeToHtml($block));
    }

    public function testOverlayHiddenWhenResolverReturnsSideCart(): void
    {
        $block = $this->buildBlock(true, 'side_cart', 'overlay');
        $this->assertSame('', $this->invokeToHtml($block));
    }
}
