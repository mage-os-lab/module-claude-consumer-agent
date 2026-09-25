<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Catalog\Model\Category;
use MageOS\AiShoppingAssistant\Api\Backend\CatalogMapProviderInterface;

final class CategoryTree implements CatalogMapProviderInterface
{
    public function __construct(
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function map(int $storeId, array $rootIds, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        $rootIds = array_values(array_unique(array_map('intval', $rootIds)));
        return $rootIds === []
            ? $this->implicitMap($storeId, $depth)
            : $this->explicitMap($storeId, $rootIds, $depth);
    }

    private function implicitMap(int $storeId, int $depth): array
    {
        $storeRootId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
        $storeRoot = $this->loadRoot($storeRootId, $storeId);
        if ($storeRoot === null) {
            return [];
        }

        $topLevel = $storeRoot['level'] + 1;
        $rows = $this->loadDescendants($storeId, $storeRoot['path'], $topLevel, $storeRoot['level'] + $depth);

        $accepted = [];
        $topIds = [];
        foreach ($rows as $id => $row) {
            if ($row['level'] === $topLevel) {
                if ($row['include_in_menu'] !== 1) {
                    continue;
                }
                $topIds[] = $id;
            }
            $accepted[$id] = $row;
        }

        return $this->buildForest($accepted, $topIds);
    }

    private function explicitMap(int $storeId, array $rootIds, int $depth): array
    {
        $storeRootId = (int)$this->storeManager->getStore($storeId)->getRootCategoryId();
        $storeRoot = $this->loadRoot($storeRootId, $storeId);
        if ($storeRoot === null) {
            return [];
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['name', 'include_in_menu', 'path', 'level', 'position']);
        $collection->addIsActiveFilter();
        $collection->addFieldToFilter('path', ['like' => $storeRoot['path'] . '/%']);
        $collection->addIdFilter($rootIds);

        $roots = [];
        foreach ($collection as $item) {
            $roots[(int)$item->getId()] = $this->rowFromItem($item);
        }
        if ($roots === []) {
            return [];
        }

        $accepted = $roots;
        if ($depth > 1) {
            foreach ($roots as $root) {
                $descendants = $this->loadDescendants(
                    $storeId,
                    $root['path'],
                    $root['level'] + 1,
                    $root['level'] + $depth - 1
                );
                $accepted += $descendants;
            }
        }

        $topIds = array_values(array_filter($rootIds, static fn (int $id): bool => isset($accepted[$id])));
        return $this->buildForest($accepted, $topIds);
    }

    private function loadRoot(int $categoryId, int $storeId): ?array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['path', 'level']);
        $collection->addIdFilter([$categoryId]);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return ['path' => (string)$item->getPath(), 'level' => (int)$item->getLevel()];
    }

    private function loadDescendants(int $storeId, string $rootPath, int $minLevel, int $maxLevel): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['name', 'include_in_menu', 'path', 'level', 'position']);
        $collection->addIsActiveFilter();
        $collection->addFieldToFilter('path', ['like' => $rootPath . '/%']);
        $collection->addFieldToFilter('level', ['from' => $minLevel, 'to' => $maxLevel]);

        $rows = [];
        foreach ($collection as $item) {
            $rows[(int)$item->getId()] = $this->rowFromItem($item);
        }
        return $rows;
    }

    private function rowFromItem(Category $item): array
    {
        return [
            'name' => (string)$item->getName(),
            'include_in_menu' => (int)$item->getData('include_in_menu'),
            'path' => (string)$item->getPath(),
            'level' => (int)$item->getLevel(),
            'position' => (int)$item->getPosition(),
        ];
    }

    private function buildForest(array $accepted, array $topIds): array
    {
        $childrenByParent = [];
        foreach ($accepted as $id => $row) {
            $segments = explode('/', $row['path']);
            $parentId = (int)($segments[count($segments) - 2] ?? 0);
            $childrenByParent[$parentId][] = $id;
        }
        foreach ($childrenByParent as $parentId => $ids) {
            usort($ids, static fn (int $a, int $b): int => $accepted[$a]['position'] <=> $accepted[$b]['position']);
            $childrenByParent[$parentId] = $ids;
        }

        $nodes = [];
        foreach ($topIds as $id) {
            $nodes[] = $this->buildNode($id, $accepted, $childrenByParent);
        }
        return $nodes;
    }

    private function buildNode(int $id, array $accepted, array $childrenByParent): array
    {
        $children = [];
        foreach ($childrenByParent[$id] ?? [] as $childId) {
            $children[] = $this->buildNode($childId, $accepted, $childrenByParent);
        }
        return ['id' => $id, 'name' => $accepted[$id]['name'], 'children' => $children];
    }
}
