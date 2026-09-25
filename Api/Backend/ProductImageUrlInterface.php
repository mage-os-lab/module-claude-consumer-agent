<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use Magento\Catalog\Api\Data\ProductInterface;

interface ProductImageUrlInterface
{
    /**
     * Returns the image URL for a product, or null when the store layer has none to offer.
     */
    public function forProduct(ProductInterface $product, string $imageId, int $storeId): ?string;
}
