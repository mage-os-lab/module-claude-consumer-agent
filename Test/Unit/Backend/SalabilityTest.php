<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Website;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\Salability;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SalabilityTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('session-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function product(int $id): ProductInterface&MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    private function legacyOnlySalability(mixed $rawStockStatus): Salability
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willThrowException(new \RuntimeException('MSI not available'));

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stockStatus = $this->createMock(StockStatusInterface::class);
        $stockStatus->method('getStockStatus')->willReturn($rawStockStatus);
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockStatus')->willReturn($stockStatus);

        return new Salability($objectManager, $stockRegistry, $storeManager);
    }

    public function testInStockStatusCodeIsSalable(): void
    {
        $salability = $this->legacyOnlySalability(StockStatusInterface::STATUS_IN_STOCK);

        $this->assertTrue($salability->isSalable($this->product(1), $this->context()));
    }

    public function testOutOfStockStatusCodeIsNotSalable(): void
    {
        $salability = $this->legacyOnlySalability(StockStatusInterface::STATUS_OUT_OF_STOCK);

        $this->assertFalse($salability->isSalable($this->product(1), $this->context()));
    }

    public function testStringStockStatusFromTheUntypedColumnIsCastBeforeComparison(): void
    {
        $salability = $this->legacyOnlySalability((string)StockStatusInterface::STATUS_IN_STOCK);

        $this->assertTrue($salability->isSalable($this->product(1), $this->context()));
    }

    public function testMsiCheckUsesTheCatalogSkuNotTheOptionComposedSku(): void
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn(9);
        $product->method('getData')->with('sku')->willReturn('engravable');
        $product->method('getSku')->willReturn('engravable-engrave-none');

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);
        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->expects($this->once())->method('execute')->with('engravable', 1)->willReturn(true);
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

        $salability = new Salability($objectManager, $this->createMock(StockRegistryInterface::class), $storeManager);

        $this->assertTrue($salability->isSalable($product, $this->context()));
    }
}
