<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

final class AllowedCategories
{
    public function __construct(
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig
    ) {
    }

    public function ids(int $storeId): array
    {
        return array_map('intval', $this->storeConfig->agent($storeId)->allowedCategories);
    }

    public function permits(array $categoryIds, int $storeId): bool
    {
        $allowed = $this->ids($storeId);
        if ($allowed === []) {
            return true;
        }

        $ids = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($ids === []) {
            return false;
        }
        if (array_intersect($ids, $allowed) !== []) {
            return true;
        }

        return $this->hasAllowedAncestor($ids, $allowed, $storeId);
    }

    private function hasAllowedAncestor(array $categoryIds, array $allowed, int $storeId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['path']);
        $collection->addIdFilter($categoryIds);

        foreach ($collection as $category) {
            $ancestors = array_map('intval', explode('/', (string)$category->getPath()));
            if (array_intersect($ancestors, $allowed) !== []) {
                return true;
            }
        }
        return false;
    }
}
