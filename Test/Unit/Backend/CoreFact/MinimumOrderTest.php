<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\MinimumOrder;
use PHPUnit\Framework\TestCase;

final class MinimumOrderTest extends TestCase
{
    public function testReturnsTheLineWithTheStoreCurrencyWhenActive(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('50');

        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('CAD');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $provider = new MinimumOrder($scopeConfig, $storeManager);

        $this->assertSame('Minimum order: 50 CAD.', $provider->line(1));
    }

    public function testFormatsADecimalAmount(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('49.99');

        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $provider = new MinimumOrder($scopeConfig, $storeManager);

        $this->assertSame('Minimum order: 49.99 USD.', $provider->line(1));
    }

    public function testReturnsNullWhenInactive(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeManager = $this->createMock(StoreManagerInterface::class);

        $provider = new MinimumOrder($scopeConfig, $storeManager);

        $this->assertNull($provider->line(1));
    }

    public function testReturnsNullWhenTheStoreCannotBeResolved(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('50');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willThrowException(new NoSuchEntityException());

        $provider = new MinimumOrder($scopeConfig, $storeManager);

        $this->assertNull($provider->line(1));
    }
}
