<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Surface;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Surface\PageDetector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PageDetectorTest extends TestCase
{
    private function request(string $fullActionName, array $params = []): Http&MockObject
    {
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn($fullActionName);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name, mixed $defaultValue = null): mixed => $params[$name] ?? $defaultValue
        );
        return $request;
    }

    private function storeManager(int $storeId = 1): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function detector(
        Http $request,
        ?CategoryRepositoryInterface $categoryRepository = null,
        ?StoreManagerInterface $storeManager = null,
        ?ProductRepositoryInterface $productRepository = null
    ): PageDetector {
        return new PageDetector(
            $request,
            $categoryRepository ?? $this->createMock(CategoryRepositoryInterface::class),
            $productRepository ?? $this->createMock(ProductRepositoryInterface::class),
            $storeManager ?? $this->storeManager()
        );
    }

    public function testCmsIndexIndexMapsToHome(): void
    {
        $page = $this->detector($this->request('cms_index_index'))->detect();

        $this->assertSame('home', $page['page_type']);
        $this->assertNull($page['product_id']);
        $this->assertNull($page['category_id']);
    }

    public function testCatalogsearchResultIndexMapsToSearchWithTrimmedQuery(): void
    {
        $page = $this->detector($this->request('catalogsearch_result_index', ['q' => '  blue widget  ']))->detect();

        $this->assertSame('search', $page['page_type']);
        $this->assertSame('blue widget', $page['query']);
    }

    public function testCatalogsearchResultIndexTruncatesQueryAt200Chars(): void
    {
        $longQuery = str_repeat('a', 250);

        $page = $this->detector($this->request('catalogsearch_result_index', ['q' => $longQuery]))->detect();

        $this->assertSame(str_repeat('a', 200), $page['query']);
    }

    public function testCatalogsearchResultIndexLeavesQueryNullWhenMissing(): void
    {
        $page = $this->detector($this->request('catalogsearch_result_index'))->detect();

        $this->assertSame('search', $page['page_type']);
        $this->assertNull($page['query']);
    }

    public function testCatalogProductViewMapsToProductWithProductId(): void
    {
        $page = $this->detector($this->request('catalog_product_view', ['id' => '4455']))->detect();

        $this->assertSame('product', $page['page_type']);
        $this->assertSame('4455', $page['product_id']);
    }

    public function testCatalogProductViewMapsToProductWithNameLookup(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getName')->willReturn('The Interior Design Handbook');
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->once())
            ->method('getById')
            ->with(4455, false, 1)
            ->willReturn($product);

        $page = $this->detector(
            $this->request('catalog_product_view', ['id' => '4455']),
            null,
            null,
            $productRepository
        )->detect();

        $this->assertSame('product', $page['page_type']);
        $this->assertSame('4455', $page['product_id']);
        $this->assertSame('The Interior Design Handbook', $page['product_name']);
    }

    public function testCatalogProductViewLeavesNameNullOnNoSuchEntityException(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willThrowException(new NoSuchEntityException());

        $page = $this->detector(
            $this->request('catalog_product_view', ['id' => '9999']),
            null,
            null,
            $productRepository
        )->detect();

        $this->assertSame('product', $page['page_type']);
        $this->assertSame('9999', $page['product_id']);
        $this->assertNull($page['product_name']);
    }

    public function testCatalogProductViewLeavesProductIdAndNameNullWhenIdParamIsMissing(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->never())->method('getById');

        $page = $this->detector(
            $this->request('catalog_product_view'),
            null,
            null,
            $productRepository
        )->detect();

        $this->assertSame('product', $page['page_type']);
        $this->assertNull($page['product_id']);
        $this->assertNull($page['product_name']);
    }

    public function testCatalogCategoryViewMapsToCategoryWithNameLookup(): void
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getName')->willReturn('Rugs');
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->expects($this->once())
            ->method('get')
            ->with(1050, 1)
            ->willReturn($category);

        $page = $this->detector(
            $this->request('catalog_category_view', ['id' => '1050']),
            $categoryRepository
        )->detect();

        $this->assertSame('category', $page['page_type']);
        $this->assertSame('1050', $page['category_id']);
        $this->assertSame('Rugs', $page['category_name']);
    }

    public function testCatalogCategoryViewLeavesNameNullOnNoSuchEntityException(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->method('get')->willThrowException(new NoSuchEntityException());

        $page = $this->detector(
            $this->request('catalog_category_view', ['id' => '9999']),
            $categoryRepository
        )->detect();

        $this->assertSame('category', $page['page_type']);
        $this->assertSame('9999', $page['category_id']);
        $this->assertNull($page['category_name']);
    }

    public function testCatalogCategoryViewLeavesCategoryIdAndNameNullWhenIdParamIsMissing(): void
    {
        $categoryRepository = $this->createMock(CategoryRepositoryInterface::class);
        $categoryRepository->expects($this->never())->method('get');

        $page = $this->detector($this->request('catalog_category_view'), $categoryRepository)->detect();

        $this->assertSame('category', $page['page_type']);
        $this->assertNull($page['category_id']);
        $this->assertNull($page['category_name']);
    }

    public function testCheckoutCartIndexMapsToCart(): void
    {
        $page = $this->detector($this->request('checkout_cart_index'))->detect();

        $this->assertSame('cart', $page['page_type']);
    }

    public function testSalesOrderPrefixedActionMapsToOrders(): void
    {
        $page = $this->detector($this->request('sales_order_view'))->detect();

        $this->assertSame('orders', $page['page_type']);
    }

    public function testUnknownActionMapsToOther(): void
    {
        $page = $this->detector($this->request('cms_page_view'))->detect();

        $this->assertSame('other', $page['page_type']);
    }
}
