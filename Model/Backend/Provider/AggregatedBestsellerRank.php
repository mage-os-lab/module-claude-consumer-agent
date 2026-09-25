<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use MageOS\AiShoppingAssistant\Api\Backend\BestsellerRankInterface;

final class AggregatedBestsellerRank implements BestsellerRankInterface
{
    private const TABLE_YEARLY = 'sales_bestsellers_aggregated_yearly';
    private const TABLE_SUPER_LINK = 'catalog_product_super_link';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function rank(array $productIds, int $storeId): array
    {
        $candidateIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($candidateIds === []) {
            return [];
        }

        $childIdsByParent = $this->childIdsByParent($candidateIds);
        $childIds = array_merge(...array_values($childIdsByParent));
        $salesIds = array_values(array_unique(array_merge($candidateIds, $childIds)));
        $qtyById = $this->qtyOrderedById($salesIds, $storeId);

        $totals = [];
        foreach ($candidateIds as $productId) {
            $total = $qtyById[$productId] ?? 0.0;
            foreach ($childIdsByParent[$productId] ?? [] as $childId) {
                $total += $qtyById[$childId] ?? 0.0;
            }
            $totals[$productId] = $total;
        }

        $ranked = $candidateIds;
        usort($ranked, static function (int $a, int $b) use ($totals): int {
            $totalA = $totals[$a];
            $totalB = $totals[$b];
            if ($totalA === 0.0 && $totalB === 0.0) {
                return 0;
            }
            if ($totalA === 0.0) {
                return 1;
            }
            if ($totalB === 0.0) {
                return -1;
            }
            return $totalB <=> $totalA;
        });

        return $ranked;
    }

    /**
     * @return array<int, int[]>
     */
    private function childIdsByParent(array $candidateIds): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE_SUPER_LINK), ['parent_id', 'product_id'])
            ->where('parent_id IN (?)', $candidateIds);
        $rows = $connection->fetchAll($select);

        $childIdsByParent = [];
        foreach ($rows as $row) {
            $childIdsByParent[(int)$row['parent_id']][] = (int)$row['product_id'];
        }
        return $childIdsByParent;
    }

    /**
     * @return array<int, float>
     */
    private function qtyOrderedById(array $productIds, int $storeId): array
    {
        if ($productIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::TABLE_YEARLY),
                ['product_id', 'qty' => new \Zend_Db_Expr('SUM(qty_ordered)')]
            )
            ->where('store_id = ?', $storeId)
            ->where('period >= ?', $this->firstDayOfLastYear())
            ->where('product_id IN (?)', $productIds)
            ->group('product_id');
        $rows = $connection->fetchPairs($select);

        $qtyById = [];
        foreach ($rows as $productId => $qty) {
            $qtyById[(int)$productId] = (float)$qty;
        }
        return $qtyById;
    }

    private function firstDayOfLastYear(): string
    {
        $lastYear = (int)(new \DateTimeImmutable('now'))->format('Y') - 1;
        return $lastYear . '-01-01';
    }
}
