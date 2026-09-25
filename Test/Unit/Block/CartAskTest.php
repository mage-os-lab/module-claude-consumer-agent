<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Block\CartAsk;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CartAskTest extends TestCase
{
    private function buildBlock(ScopeConfigInterface $scopeConfig): CartAsk
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $storeConfig = new StoreConfig($scopeConfig, $storeManager);

        $context = $this->createMock(Context::class);
        $context->method('getStoreManager')->willReturn($storeManager);

        return new CartAsk($context, $storeConfig, []);
    }

    private function invokeToHtml(CartAsk $block): string
    {
        $method = new ReflectionMethod(CartAsk::class, '_toHtml');
        return (string)$method->invoke($block);
    }

    public function testRendersEmptyWhenModuleDisabled(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $block = $this->buildBlock($scopeConfig);
        $this->assertSame('', $this->invokeToHtml($block));
    }

    public function testChecksEnabledFlagOnTheCurrentStoreWhenModuleEnabled(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with('ai_integration/aiagent/general/enabled', ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);
        $block = $this->buildBlock($scopeConfig);
        $this->invokeToHtml($block);
    }
}
