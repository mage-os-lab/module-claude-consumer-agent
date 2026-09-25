<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Eval;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Data\SearchFilters;
use MageOS\AiShoppingAssistant\Model\Eval\FakeBackend;
use PHPUnit\Framework\TestCase;

class FakeBackendTest extends TestCase
{
    public function testSearchProductsMatchesByTitleToken(): void
    {
        $backend = new FakeBackend([
            'SKU-1' => ['product_id' => 'SKU-1', 'title' => 'Budget Blender X200', 'price' => 39.0, 'in_stock' => true],
            'SKU-2' => ['product_id' => 'SKU-2', 'title' => 'Steel Water Bottle', 'price' => 24.0, 'in_stock' => true],
        ]);

        $results = $backend->searchProducts($this->context(), 'cheap blender', null, 8);

        $this->assertCount(1, $results);
        $this->assertSame('SKU-1', $results[0]->getProductId());
    }

    public function testSearchProductsSortsByQtyOrderedDescendingWithMissingLast(): void
    {
        $backend = new FakeBackend([
            'RG-1' => ['product_id' => 'RG-1', 'title' => 'Wool Rug', 'price' => 220.0, 'in_stock' => true, 'category_ids' => ['1050'], 'qty_ordered' => 40],
            'RG-2' => ['product_id' => 'RG-2', 'title' => 'Jute Rug', 'price' => 95.0, 'in_stock' => true, 'category_ids' => ['1050'], 'qty_ordered' => 65],
            'RG-3' => ['product_id' => 'RG-3', 'title' => 'Cotton Rug', 'price' => 60.0, 'in_stock' => true, 'category_ids' => ['1050']],
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 1050, 'sort' => 'best_sellers']);
        $results = $backend->searchProducts($this->context(), '', $filters, 8);

        $this->assertSame(['RG-2', 'RG-1', 'RG-3'], array_map(
            static fn ($product): string => $product->getProductId(),
            $results
        ));
    }

    public function testSearchProductsIgnoresSortWhenNotBestSellers(): void
    {
        $backend = new FakeBackend([
            'RG-1' => ['product_id' => 'RG-1', 'title' => 'Wool Rug', 'price' => 220.0, 'in_stock' => true, 'category_ids' => ['1050'], 'qty_ordered' => 40],
            'RG-2' => ['product_id' => 'RG-2', 'title' => 'Jute Rug', 'price' => 95.0, 'in_stock' => true, 'category_ids' => ['1050'], 'qty_ordered' => 65],
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 1050]);
        $results = $backend->searchProducts($this->context(), '', $filters, 8);

        $this->assertSame(['RG-1', 'RG-2'], array_map(
            static fn ($product): string => $product->getProductId(),
            $results
        ));
    }

    public function testGetProductDetailsReturnsNullForUnknownId(): void
    {
        $backend = new FakeBackend();

        $this->assertNull($backend->getProductDetails($this->context(), 'missing'));
    }

    public function testGetProductDetailsAttachesVariants(): void
    {
        $backend = new FakeBackend([
            'FAM-1' => ['product_id' => 'FAM-1', 'title' => 'Family', 'price' => 10.0, 'in_stock' => true],
            'FAM-1-A' => ['product_id' => 'FAM-1-A', 'title' => 'Family A', 'price' => 10.0, 'in_stock' => true, 'variant_of' => 'FAM-1'],
        ]);

        $details = $backend->getProductDetails($this->context(), 'FAM-1');

        $this->assertNotNull($details);
        $this->assertCount(1, $details->getVariants());
        $this->assertSame('FAM-1-A', $details->getVariants()[0]->getProductId());
    }

    public function testAddToCartAccumulatesQuantity(): void
    {
        $backend = new FakeBackend(['SKU-1' => ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'in_stock' => true]]);
        $context = $this->context();

        $backend->addToCart($context, 'SKU-1', 2);
        $cart = $backend->addToCart($context, 'SKU-1', 3);

        $this->assertSame(5, $cart->find('SKU-1')->getQuantity());
    }

    public function testAddToCartThrowsUnavailableForUnknownProduct(): void
    {
        $backend = new FakeBackend();

        $this->expectException(Unavailable::class);
        $backend->addToCart($this->context(), 'missing', 1);
    }

    public function testAddToCartThrowsUnavailableForOutOfStockProduct(): void
    {
        $backend = new FakeBackend(['SKU-1' => ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'in_stock' => false]]);

        $this->expectException(Unavailable::class);
        $backend->addToCart($this->context(), 'SKU-1', 1);
    }

    public function testUpdateCartItemSetsQuantity(): void
    {
        $backend = new FakeBackend(['SKU-1' => ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'in_stock' => true]]);
        $context = $this->context();
        $backend->addToCart($context, 'SKU-1', 1);

        $cart = $backend->updateCartItem($context, 'SKU-1', 9);

        $this->assertSame(9, $cart->find('SKU-1')->getQuantity());
    }

    public function testRemoveFromCartRemovesLine(): void
    {
        $backend = new FakeBackend(['SKU-1' => ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'in_stock' => true]]);
        $context = $this->context();
        $backend->addToCart($context, 'SKU-1', 1);

        $cart = $backend->removeFromCart($context, 'SKU-1');

        $this->assertTrue($cart->isEmpty());
    }

    public function testGetOrdersThrowsSignInRequiredForGuest(): void
    {
        $backend = new FakeBackend();

        $this->expectException(SignInRequired::class);
        $backend->getOrders($this->context(null), 5);
    }

    public function testGetOrdersReturnsSeededOrdersForSignedInCustomer(): void
    {
        $backend = new FakeBackend([], [], [
            ['order_id' => 'ORD-1', 'status' => 'shipped', 'total' => 10.0, 'items' => []],
        ]);

        $orders = $backend->getOrders($this->context(42), 5);

        $this->assertCount(1, $orders);
        $this->assertSame('ORD-1', $orders[0]->getOrderId());
    }

    public function testGetOrderThrowsSignInRequiredForGuest(): void
    {
        $backend = new FakeBackend();

        $this->expectException(SignInRequired::class);
        $backend->getOrder($this->context(null), 'ORD-1');
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $backend = new FakeBackend();

        $this->assertNull($backend->getOrder($this->context(42), 'missing'));
    }

    public function testSearchPoliciesFiltersByQuery(): void
    {
        $backend = new FakeBackend([], [], [], [
            ['policy_id' => 'pol-1', 'title' => 'Returns', 'content' => 'Return within 30 days.'],
            ['policy_id' => 'pol-2', 'title' => 'Shipping', 'content' => 'Ships in two days.'],
        ]);

        $matches = $backend->searchPolicies($this->context(), 'return');

        $this->assertCount(1, $matches);
        $this->assertSame('pol-1', $matches[0]->getPolicyId());
    }

    public function testGetFulfillmentOptionsReturnsSeededOptions(): void
    {
        $backend = new FakeBackend([], [], [], [], [
            ['method' => 'delivery', 'eta' => 'Tomorrow', 'fee' => 0.0],
        ]);

        $options = $backend->getFulfillmentOptions($this->context(), ['SKU-1']);

        $this->assertCount(1, $options);
        $this->assertSame('delivery', $options[0]->getMethod());
    }

    public function testSearchCategoriesMatchesByNameSubstring(): void
    {
        $backend = new FakeBackend([], [], [], [], [], [
            ['category_id' => 5, 'name' => 'Lounge Chairs', 'path' => ['Seating', 'Lounge Chairs'], 'product_count' => 12, 'level' => 3],
            ['category_id' => 6, 'name' => 'Rugs', 'path' => ['Rugs'], 'product_count' => 4, 'level' => 2],
        ]);

        $matches = $backend->searchCategories($this->context(), 'lounge', 8);

        $this->assertCount(1, $matches);
        $this->assertSame(5, $matches[0]->getCategoryId());
    }

    private function context(?int $customerId = null): SessionContext
    {
        return new SessionContext('sess-1', $customerId, 1, 1, new PageContext(), new \DateTimeImmutable());
    }
}
