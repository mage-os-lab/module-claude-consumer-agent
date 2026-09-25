<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

/**
 * Reads Magento\Framework\ObjectManagerInterface directly to resolve the two optional
 * MSI (Multi Source Inventory) interfaces without hard-coupling the module to them.
 */
final class Salability
{
    public function __construct(
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function isSalable(ProductInterface $p, SessionContext $ctx): bool
    {
        if (interface_exists(IsProductSalableInterface::class) && interface_exists(StockResolverInterface::class)) {
            $result = $this->isSalableByMsi($p, $ctx);
            if ($result !== null) {
                return $result;
            }
        }

        $websiteId = (int)$this->storeManager->getStore($ctx->storeId)->getWebsiteId();
        $stockStatus = (int)$this->stockRegistry->getStockStatus((int)$p->getId(), $websiteId)->getStockStatus();
        return $stockStatus === StockStatusInterface::STATUS_IN_STOCK;
    }

    private function isSalableByMsi(ProductInterface $p, SessionContext $ctx): ?bool
    {
        try {
            $stockResolver = $this->objectManager->get(StockResolverInterface::class);
            $isProductSalable = $this->objectManager->get(IsProductSalableInterface::class);
            $websiteCode = $this->storeManager->getStore($ctx->storeId)->getWebsite()->getCode();
            $stockId = $stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
            if ($stockId === null) {
                return null;
            }
            return $isProductSalable->execute($this->canonicalSku($p), $stockId);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @param ProductInterface[] $products
     * @return array<int, bool>
     */
    public function areSalable(array $products, SessionContext $ctx): array
    {
        if ($products === []) {
            return [];
        }

        if (interface_exists(AreProductsSalableInterface::class) && interface_exists(StockResolverInterface::class)) {
            $result = $this->areSalableByMsi($products, $ctx);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->areSalableByStockRegistry($products, $ctx);
    }

    private function areSalableByMsi(array $products, SessionContext $ctx): ?array
    {
        try {
            $stockResolver = $this->objectManager->get(StockResolverInterface::class);
            $areProductsSalable = $this->objectManager->get(AreProductsSalableInterface::class);
            $websiteCode = $this->storeManager->getStore($ctx->storeId)->getWebsite()->getCode();
            $stockId = $stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
            if ($stockId === null) {
                return null;
            }

            $skusByProductId = [];
            foreach ($products as $product) {
                $skusByProductId[(int)$product->getId()] = $this->canonicalSku($product);
            }

            $results = $areProductsSalable->execute(array_values($skusByProductId), $stockId);
            $salableBySku = [];
            foreach ($results as $result) {
                $salableBySku[$result->getSku()] = $result->isSalable();
            }

            $map = [];
            foreach ($skusByProductId as $productId => $sku) {
                $map[$productId] = $salableBySku[$sku] ?? false;
            }
            return $map;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * @param ProductInterface[] $products
     * @return array<int, bool>
     */
    private function areSalableByStockRegistry(array $products, SessionContext $ctx): array
    {
        $websiteId = (int)$this->storeManager->getStore($ctx->storeId)->getWebsiteId();

        $map = [];
        foreach ($products as $product) {
            $productId = (int)$product->getId();
            $stockStatus = (int)$this->stockRegistry->getStockStatus($productId, $websiteId)->getStockStatus();
            $map[$productId] = $stockStatus === StockStatusInterface::STATUS_IN_STOCK;
        }
        return $map;
    }

    private function canonicalSku(ProductInterface $product): string
    {
        if ($product instanceof DataObject) {
            return (string)$product->getData('sku');
        }
        return (string)$product->getSku();
    }
}
