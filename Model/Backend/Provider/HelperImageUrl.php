<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\AiShoppingAssistant\Api\Backend\ProductImageUrlInterface;

final class HelperImageUrl implements ProductImageUrlInterface
{
    public function __construct(
        private readonly \Magento\Catalog\Helper\Image $imageHelper
    ) {
    }

    public function forProduct(ProductInterface $product, string $imageId, int $storeId): ?string
    {
        return $this->imageHelper->init($product, $imageId)->getUrl();
    }
}
