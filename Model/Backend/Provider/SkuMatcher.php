<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;

final class SkuMatcher implements SkuMatcherInterface
{
    private const TABLE_PRODUCT = 'catalog_product_entity';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function firstExisting(array $skus, int $storeId): ?string
    {
        $candidates = array_values(array_filter(
            array_map('strval', $skus),
            static fn (string $sku): bool => $sku !== ''
        ));
        if ($candidates === []) {
            return null;
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE_PRODUCT), ['sku'])
            ->where('sku IN (?)', $candidates)
            ->limit(1);
        $sku = $connection->fetchOne($select);
        if (!is_string($sku) || $sku === '') {
            return null;
        }
        return $sku;
    }
}
