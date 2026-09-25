<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;

/**
 * SKU lookup for the eval runner: the case's catalog ids stand in for the product table,
 * so a fixture run never queries the database to decide whether a token is a product.
 */
final class InMemorySkuMatcher implements SkuMatcherInterface
{
    public function __construct(
        private readonly array $skus = []
    ) {
    }

    public function firstExisting(array $skus, int $storeId): ?string
    {
        foreach ($skus as $candidate) {
            foreach ($this->skus as $sku) {
                if (strcasecmp((string)$sku, (string)$candidate) === 0) {
                    return (string)$sku;
                }
            }
        }
        return null;
    }
}
