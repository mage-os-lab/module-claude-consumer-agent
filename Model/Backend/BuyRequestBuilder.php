<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;
use MageOS\AiShoppingAssistant\Api\Cart\BuyRequestBuilderInterface;

final class BuyRequestBuilder implements BuyRequestBuilderInterface
{
    public function build(ProductInterface $product, int $qty, array $selections): DataObject
    {
        $data = [
            'qty' => $qty,
            'product' => (int)$product->getId(),
        ];

        $superAttribute = $selections['super_attribute'] ?? null;
        if (is_array($superAttribute) && $superAttribute !== []) {
            $data['super_attribute'] = $superAttribute;
        }

        $options = $selections['options'] ?? null;
        if (is_array($options) && $options !== []) {
            $data['options'] = $options;
        }

        return new DataObject($data);
    }
}
