<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

interface BestsellerRankInterface
{
    /**
     * @param int[] $productIds
     * @return int[]
     */
    public function rank(array $productIds, int $storeId): array;
}
