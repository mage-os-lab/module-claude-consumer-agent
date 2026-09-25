<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use MageOS\AiShoppingAssistant\Api\Backend\SearchProviderInterface;
use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

final class BrandCapSearchDecorator implements SearchProviderInterface
{
    private const SORT_BEST_SELLERS = 'best_sellers';
    private const BRAND_CAP_PAGE_SIZE_MULTIPLIER = 3;
    private const BRAND_CAP_PAGE_SIZE_CAP = 30;
    private const BRAND_SHARE_DIVISOR = 3;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\SearchProviderInterface $provider,
        private readonly \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory
    ) {
    }

    public function search(SessionContext $ctx, string $query, ?SearchFiltersInterface $filters, int $limit): array
    {
        $isBestSellers = $filters !== null && $filters->getSort() === self::SORT_BEST_SELLERS;
        if ($query === '' || $isBestSellers) {
            return $this->provider->search($ctx, $query, $filters, $limit);
        }

        $ids = $this->provider->search($ctx, $query, $filters, $this->brandCapFetchLimit($limit));
        return $this->capByBrand($ids, $query, $limit, $ctx->storeId);
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
}
