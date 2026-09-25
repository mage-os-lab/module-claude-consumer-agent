<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Catalog\Model\Category;
use MageOS\AiShoppingAssistant\Api\Backend\CategorySearchProviderInterface;
use MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\CategoryMatch;

final class CategoryNameSearch implements CategorySearchProviderInterface
{
    private const MIN_WORD_LENGTH = 3;
    private const CANDIDATE_CAP = 200;

    public function __construct(
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly \Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer $categoryProductTableMaintainer
    ) {
    }

    public function search(SessionContext $ctx, string $keywords, int $limit): array
    {
        $words = $this->tokenize($keywords);
        if ($words === []) {
            return [];
        }

        $storeRootId = (int)$this->storeManager->getStore($ctx->storeId)->getRootCategoryId();
        $storeRoot = $this->loadRoot($storeRootId, $ctx->storeId);
        if ($storeRoot === null) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($ctx->storeId);
        $collection->addAttributeToSelect(['name', 'path', 'level', 'position']);
        $collection->addIsActiveFilter();
        $collection->addFieldToFilter('path', ['like' => $storeRoot['path'] . '/%']);
        $collection->addAttributeToFilter(
            array_map(
                fn (string $word): array => ['attribute' => 'name', 'regexp' => '(?i)' . $this->wordPattern($word)],
                $words
            )
        );
        $collection->setPageSize(self::CANDIDATE_CAP);

        $candidates = [];
        foreach ($collection as $item) {
            $candidates[(int)$item->getId()] = $this->rank($item, $words);
        }
        if ($candidates === []) {
            return [];
        }

        uasort(
            $candidates,
            static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']
        );
        $top = array_slice($candidates, 0, max(1, $limit), true);

        $counts = $this->productCounts(array_keys($top), $ctx->storeId);
        $ancestorNames = $this->ancestorNames($top, $storeRootId, $ctx->storeId);

        $matches = [];
        foreach ($top as $categoryId => $row) {
            $matches[] = CategoryMatch::fromArray([
                'category_id' => $categoryId,
                'name' => $row['name'],
                'path' => $this->pathNames($row['path'], $storeRootId, $ancestorNames),
                'product_count' => $counts[$categoryId] ?? 0,
                'level' => $row['level'],
            ]);
        }
        return $matches;
    }

    private function tokenize(string $keywords): array
    {
        $parts = preg_split('/[\s,]+/', trim($keywords), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_filter(
            $parts !== false ? $parts : [],
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH
        );
        return array_values(array_unique(array_map('mb_strtolower', $words)));
    }

    private function wordPattern(string $word): string
    {
        $stem = preg_replace('/(es|s)$/u', '', $word);
        if ($stem === null || mb_strlen($stem) < self::MIN_WORD_LENGTH) {
            $stem = $word;
        }
        return '\\b' . preg_quote($stem, '/') . '(es|s)?\\b';
    }

    private function rank(Category $item, array $words): array
    {
        $name = (string)$item->getName();
        $nameLower = mb_strtolower($name);
        $matchedWords = 0;
        foreach ($words as $word) {
            if (preg_match('/' . $this->wordPattern($word) . '/iu', $name) === 1) {
                $matchedWords++;
            }
        }
        $exactMatch = in_array($nameLower, $words, true) || $nameLower === mb_strtolower(implode(' ', $words));
        $level = (int)$item->getLevel();
        $position = (int)$item->getPosition();

        return [
            'name' => $name,
            'path' => (string)$item->getPath(),
            'level' => $level,
            'rank' => [$exactMatch ? 0 : 1, -$matchedWords, $level, $position],
        ];
    }

    private function loadRoot(int $categoryId, int $storeId): ?array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['path']);
        $collection->addIdFilter([$categoryId]);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return ['path' => (string)$item->getPath()];
    }

    private function productCounts(array $categoryIds, int $storeId): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $table = $this->categoryProductTableMaintainer->getMainTable($storeId);
        $select = $connection->select()
            ->from($table, ['category_id', 'count' => new \Zend_Db_Expr('COUNT(product_id)')])
            ->where('category_id IN (?)', $categoryIds)
            ->where('store_id = ?', $storeId)
            ->group('category_id');
        $rows = $connection->fetchPairs($select);
        return array_map('intval', $rows);
    }

    private function ancestorNames(array $rows, int $storeRootId, int $storeId): array
    {
        $ids = [];
        foreach ($rows as $row) {
            foreach ($this->segmentsAfterRoot($row['path'], $storeRootId) as $id) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['name']);
        $collection->addIdFilter($ids);

        $names = [];
        foreach ($collection as $item) {
            $names[(int)$item->getId()] = (string)$item->getName();
        }
        return $names;
    }

    private function pathNames(string $path, int $storeRootId, array $ancestorNames): array
    {
        $names = [];
        foreach ($this->segmentsAfterRoot($path, $storeRootId) as $id) {
            if (isset($ancestorNames[$id])) {
                $names[] = $ancestorNames[$id];
            }
        }
        return $names;
    }

    private function segmentsAfterRoot(string $path, int $storeRootId): array
    {
        $segments = explode('/', $path);
        $rootIndex = array_search((string)$storeRootId, $segments, true);
        if ($rootIndex === false) {
            return [];
        }
        return array_map('intval', array_slice($segments, $rootIndex + 1));
    }
}
