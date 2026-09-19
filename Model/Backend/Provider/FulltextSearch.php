<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Backend\Provider;

use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\SearchFiltersInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;

final class FulltextSearch implements SearchProviderInterface
{
    private const SORT_BEST_SELLERS = 'best_sellers';
    private const BEST_SELLERS_PAGE_SIZE_MULTIPLIER = 5;
    private const BEST_SELLERS_PAGE_SIZE_CAP = 60;
    private const BRAND_CAP_PAGE_SIZE_MULTIPLIER = 3;
    private const BRAND_CAP_PAGE_SIZE_CAP = 30;
    private const BRAND_SHARE_DIVISOR = 3;

    public function __construct(
        private readonly \Magento\Framework\Api\Search\SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly \Magento\Framework\Api\FilterBuilder $filterBuilder,
        private readonly \Magento\Search\Api\SearchInterface $search,
        private readonly \Magento\Catalog\Api\CategoryListInterface $categoryList,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $categorySearchCriteriaBuilder,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Backend\Provider\AllowedCategories $allowedCategories,
        private readonly \MageOS\ClaudeConsumerAgent\Api\Backend\BestsellerRankInterface $bestsellerRank,
        private readonly \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
    ) {
    }

    public function search(SessionContext $ctx, string $query, ?SearchFiltersInterface $filters, int $limit): array
    {
        $categoryIds = $this->categoryFilterValues($filters, $ctx->storeId);
        $isBestSellers = $filters !== null && $filters->getSort() === self::SORT_BEST_SELLERS;
        $searchLimit = $isBestSellers
            ? min($limit * self::BEST_SELLERS_PAGE_SIZE_MULTIPLIER, self::BEST_SELLERS_PAGE_SIZE_CAP)
            : $limit;

        if ($query !== '') {
            $fetchLimit = $isBestSellers ? $searchLimit : $this->brandCapFetchLimit($limit);
            $ids = $this->searchByTerm($ctx, $query, $filters, $categoryIds, $fetchLimit);
        } elseif ($categoryIds !== []) {
            $ids = $this->listCategory($ctx, $filters, $categoryIds, $searchLimit);
        } else {
            return [];
        }

        if ($query !== '' && !$isBestSellers) {
            $ids = $this->capByBrand($ids, $query, $limit, $ctx->storeId);
        }

        if (!$isBestSellers) {
            return $ids;
        }

        return array_slice($this->bestsellerRank->rank($ids, $ctx->storeId), 0, $limit);
    }

    private function searchByTerm(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        array $categoryIds,
        int $limit
    ): array {
        $criteria = $this->searchCriteriaBuilderFactory->create();
        $criteria->addFilter($this->filterBuilder->setField('search_term')->setValue($query)->create());
        $criteria->addFilter(
            $this->filterBuilder->setField('visibility')
                ->setValue([Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH])
                ->create()
        );

        $this->addPriceFilters($criteria, $filters);

        if ($categoryIds !== []) {
            $criteria->addFilter(
                $this->filterBuilder->setField('category_ids')->setValue($categoryIds)->create()
            );
        }

        [$sortField, $sortDirection] = $this->searchByTermSortOrder($filters);
        $criteria->addSortOrder($sortField, $sortDirection);
        $criteria->setPageSize($limit);

        $searchCriteria = $criteria->create();
        $searchCriteria->setRequestName('quick_search_container');

        return $this->runSearch($ctx, $searchCriteria);
    }

    private function listCategory(
        SessionContext $ctx,
        ?SearchFiltersInterface $filters,
        array $categoryIds,
        int $limit
    ): array {
        $criteria = $this->searchCriteriaBuilderFactory->create();
        $criteria->addFilter(
            $this->filterBuilder->setField('category_ids')->setValue($categoryIds)->create()
        );
        $criteria->addFilter(
            $this->filterBuilder->setField('visibility')
                ->setValue([Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH])
                ->create()
        );

        $this->addPriceFilters($criteria, $filters);

        [$sortField, $sortDirection] = $this->listSortOrder($filters !== null ? $filters->getSort() : 'relevance');
        $criteria->addSortOrder($sortField, $sortDirection);
        $criteria->setPageSize($limit);

        $searchCriteria = $criteria->create();
        $searchCriteria->setRequestName('catalog_view_container');

        return $this->runSearch($ctx, $searchCriteria);
    }

    private function addPriceFilters(
        SearchCriteriaBuilder $criteria,
        ?SearchFiltersInterface $filters
    ): void {
        if ($filters !== null && $filters->getMinPrice() !== null) {
            $criteria->addFilter(
                $this->filterBuilder->setField('price.from')->setValue((string)$filters->getMinPrice())->create()
            );
        }
        if ($filters !== null && $filters->getMaxPrice() !== null) {
            $criteria->addFilter(
                $this->filterBuilder->setField('price.to')->setValue((string)$filters->getMaxPrice())->create()
            );
        }
    }

    private function listSortOrder(string $sort): array
    {
        return match ($sort) {
            'price_asc' => ['price', SortOrder::SORT_ASC],
            'price_desc' => ['price', SortOrder::SORT_DESC],
            default => ['position', SortOrder::SORT_ASC],
        };
    }

    private function searchByTermSortOrder(?SearchFiltersInterface $filters): array
    {
        $sort = $filters !== null ? $filters->getSort() : '';
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            return $this->listSortOrder($sort);
        }
        return ['relevance', SortOrder::SORT_DESC];
    }

    private function runSearch(SessionContext $ctx, SearchCriteria $searchCriteria): array
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($ctx->storeId);

        try {
            $result = $this->search->search($searchCriteria);
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }

        $ids = [];
        foreach ($result->getItems() as $document) {
            $ids[] = (int)$document->getId();
        }
        return $ids;
    }

    private function brandCapFetchLimit(int $limit): int
    {
        return max($limit, min($limit * self::BRAND_CAP_PAGE_SIZE_MULTIPLIER, self::BRAND_CAP_PAGE_SIZE_CAP));
    }

    private function capByBrand(array $ids, string $query, int $limit, int $storeId): array
    {
        if ($ids === []) {
            return $ids;
        }

        $brands = $this->brandsByProductId($ids, $storeId);
        if ($this->queryNamesABrand($query, $brands)) {
            return array_slice($ids, 0, $limit);
        }

        $cap = max(1, intdiv($limit, self::BRAND_SHARE_DIVISOR));
        $accepted = [];
        $overflow = [];
        $brandCounts = [];

        foreach ($ids as $id) {
            if (count($accepted) >= $limit) {
                break;
            }

            $brand = $brands[$id] ?? null;
            if ($brand === null) {
                $accepted[] = $id;
                continue;
            }

            $brandCounts[$brand] = ($brandCounts[$brand] ?? 0) + 1;
            if ($brandCounts[$brand] <= $cap) {
                $accepted[] = $id;
            } else {
                $overflow[] = $id;
            }
        }

        foreach ($overflow as $id) {
            if (count($accepted) >= $limit) {
                break;
            }
            $accepted[] = $id;
        }

        return $accepted;
    }

    private function queryNamesABrand(string $query, array $brands): bool
    {
        $haystack = mb_strtolower($query);
        if ($haystack === '') {
            return false;
        }

        foreach (array_unique(array_filter($brands)) as $brand) {
            if (str_contains($haystack, mb_strtolower($brand))) {
                return true;
            }
        }
        return false;
    }

    private function brandsByProductId(array $ids, int $storeId): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect(['manufacturer']);
        $collection->addIdFilter($ids);

        $brands = [];
        foreach ($collection as $product) {
            $brands[(int)$product->getId()] = $this->brandText($product);
        }
        return $brands;
    }

    private function brandText(MagentoProductInterface $product): ?string
    {
        try {
            $text = $product->getAttributeText('manufacturer');
        } catch (\Throwable $exception) {
            return null;
        }

        if (is_array($text)) {
            $text = reset($text);
        }
        if ($text === false || $text === null) {
            return null;
        }

        $value = trim((string)$text);
        return $value !== '' ? $value : null;
    }

    private function categoryFilterValues(?SearchFiltersInterface $filters, int $storeId): array
    {
        $requested = $this->requestedCategoryId($filters, $storeId);
        if ($requested !== null && $this->allowedCategories->permits([$requested], $storeId)) {
            return [(string)$requested];
        }

        return array_map('strval', $this->allowedCategories->ids($storeId));
    }

    private function requestedCategoryId(?SearchFiltersInterface $filters, int $storeId): ?int
    {
        if ($filters === null) {
            return null;
        }
        if ($filters->getCategoryId() !== null) {
            return $filters->getCategoryId();
        }
        if ($filters->getCategory() !== null) {
            return $this->resolveCategoryId($filters->getCategory(), $storeId);
        }
        return null;
    }

    private function resolveCategoryId(string $name, int $storeId): ?int
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $criteria = $this->categorySearchCriteriaBuilder
                ->addFilter('name', $name, 'eq')
                ->setPageSize(1)
                ->create();
            $items = $this->categoryList->getList($criteria)->getItems();
            $first = reset($items);
            return $first !== false ? (int)$first->getId() : null;
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }
}
