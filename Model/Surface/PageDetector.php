<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Surface;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;

final class PageDetector
{
    private const QUERY_MAX_LENGTH = 200;

    public function __construct(
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository,
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function detect(): array
    {
        return $this->page()->toArray();
    }

    private function page(): PageContextInterface
    {
        $fullActionName = (string)$this->request->getFullActionName();

        return match (true) {
            $fullActionName === 'cms_index_index' => new PageContext(PageContextInterface::PAGE_TYPE_HOME),
            $fullActionName === 'catalogsearch_result_index' => new PageContext(
                pageType: PageContextInterface::PAGE_TYPE_SEARCH,
                query: $this->queryParam()
            ),
            $fullActionName === 'catalog_product_view' => $this->productPage(),
            $fullActionName === 'catalog_category_view' => $this->categoryPage(),
            $fullActionName === 'checkout_cart_index' => new PageContext(PageContextInterface::PAGE_TYPE_CART),
            str_starts_with($fullActionName, 'sales_order_') => new PageContext(
                PageContextInterface::PAGE_TYPE_ORDERS
            ),
            default => new PageContext(PageContextInterface::PAGE_TYPE_OTHER),
        };
    }

    private function categoryPage(): PageContextInterface
    {
        $categoryId = $this->idParam();

        return new PageContext(
            pageType: PageContextInterface::PAGE_TYPE_CATEGORY,
            categoryId: $categoryId,
            categoryName: $this->categoryName($categoryId)
        );
    }

    private function productPage(): PageContextInterface
    {
        $productId = $this->idParam();

        return new PageContext(
            pageType: PageContextInterface::PAGE_TYPE_PRODUCT,
            productId: $productId,
            productName: $this->productName($productId)
        );
    }

    private function categoryName(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            return (string)$this->categoryRepository->get((int)$categoryId, $storeId)->getName();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    private function productName(?string $productId): ?string
    {
        if ($productId === null) {
            return null;
        }
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $product = $this->productRepository->getById((int)$productId, false, $storeId);
            return $product !== null ? (string)$product->getName() : null;
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    private function idParam(): ?string
    {
        $id = $this->request->getParam('id');
        return $id !== null && $id !== '' ? (string)$id : null;
    }

    private function queryParam(): ?string
    {
        $query = trim((string)$this->request->getParam('q'));
        if ($query === '') {
            return null;
        }
        return mb_substr($query, 0, self::QUERY_MAX_LENGTH);
    }
}
