<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Surface;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Surface\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private function buildResolver(string $surfaceMode, bool $sidebarEnabled): Resolver
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($surfaceMode) {
                if ($path === 'ai_integration/aiagent/general/surface_mode') {
                    return $surfaceMode;
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($sidebarEnabled) {
                if ($path === 'checkout/sidebar/display') {
                    return $sidebarEnabled;
                }
                return false;
            }
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        return new Resolver($storeConfig, $scopeConfig);
    }

    public function testResolveReturnsExplicitSideCartMode(): void
    {
        $resolver = $this->buildResolver('side_cart', false);
        $this->assertSame('side_cart', $resolver->resolve(1, null));
    }

    public function testResolveReturnsExplicitOverlayMode(): void
    {
        $resolver = $this->buildResolver('overlay', true);
        $this->assertSame('overlay', $resolver->resolve(1, null));
    }

    public function testResolveAutoReturnsSideCartWhenSidebarEnabledAndDrawerBlockPresent(): void
    {
        $resolver = $this->buildResolver('auto', true);
        $block = $this->createMock(BlockInterface::class);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturnCallback(
            static function (string $name) use ($block) {
                return $name === 'cart-drawer' ? $block : false;
            }
        );
        $this->assertSame('side_cart', $resolver->resolve(1, $layout));
    }

    public function testResolveAutoReturnsOverlayWhenSidebarDisabled(): void
    {
        $resolver = $this->buildResolver('auto', false);
        $block = $this->createMock(BlockInterface::class);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($block);
        $this->assertSame('overlay', $resolver->resolve(1, $layout));
    }

    public function testResolveAutoReturnsSideCartWhenLayoutIsNullAndSidebarEnabled(): void
    {
        $resolver = $this->buildResolver('auto', true);
        $this->assertSame('side_cart', $resolver->resolve(1, null));
    }

    public function testResolveAutoReturnsOverlayWhenLayoutIsNullAndSidebarDisabled(): void
    {
        $resolver = $this->buildResolver('auto', false);
        $this->assertSame('overlay', $resolver->resolve(1, null));
    }

    public function testResolveAutoReturnsOverlayWhenCartDrawerBlockMissing(): void
    {
        $resolver = $this->buildResolver('auto', true);
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn(false);
        $this->assertSame('overlay', $resolver->resolve(1, $layout));
    }
}
