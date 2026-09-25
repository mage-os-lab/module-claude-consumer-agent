<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Integration\Backend;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class MagentoStorefrontTest extends TestCase
{
    private function backend(): StorefrontBackendInterface
    {
        return Bootstrap::getObjectManager()->get(StorefrontBackendInterface::class);
    }

    private function storeId(): int
    {
        return (int)Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore()->getId();
    }

    private function context(?int $customerId = null, int $quoteId = 0): SessionContext
    {
        return new SessionContext(
            'integration-session',
            $customerId,
            $quoteId,
            $this->storeId(),
            new PageContext(PageContextInterface::PAGE_TYPE_HOME),
            new \DateTimeImmutable('now')
        );
    }

    private function productId(string $sku): string
    {
        $repository = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class);
        return (string)$repository->get($sku)->getId();
    }

    private function guestQuoteId(): int
    {
        $collection = Bootstrap::getObjectManager()->get(QuoteCollectionFactory::class)->create();
        $collection->addFieldToFilter('reserved_order_id', 'aiagent_guest_quote');
        $quote = $collection->getFirstItem();
        return (int)$quote->getId();
    }

    private function reindexSearch(): void
    {
        $indexerRegistry = Bootstrap::getObjectManager()->get(IndexerRegistry::class);
        $indexerRegistry->get('catalogsearch_fulltext')->reindexAll();
    }

    /**
     * @magentoDataFixture ../_files/product_simple.php
     * @magentoDataFixture ../_files/product_configurable.php
     */
    public function testSearchReturnsPlainProductAndFamilyNotChildren(): void
    {
        $this->reindexSearch();

        $records = $this->backend()->searchProducts($this->context(), 'AI Agent', null, 20);
        $ids = array_map(static fn ($product) => $product->getProductId(), $records);

        $this->assertContains($this->productId('aiagent-simple'), $ids);
        $this->assertContains($this->productId('aiagent-configurable'), $ids);
        $this->assertNotContains($this->productId('aiagent-simple-s'), $ids);
        $this->assertNotContains($this->productId('aiagent-simple-l'), $ids);
    }

    /**
     * @magentoDataFixture ../_files/product_configurable.php
     */
    public function testGetProductDetailsMapsVariantsWithOptionValues(): void
    {
        $details = $this->backend()->getProductDetails($this->context(), $this->productId('aiagent-configurable'));

        $this->assertNotNull($details);
        $this->assertCount(2, $details->getVariants());

        $inStock = [];
        foreach ($details->getVariants() as $variant) {
            $this->assertSame($details->getProductId(), $variant->getVariantOf());
            $this->assertNotSame([], $variant->getOptionValues());
            $inStock[$variant->getProductId()] = $variant->isInStock();
        }

        $this->assertContains(true, $inStock);
        $this->assertContains(false, $inStock);
        $this->assertTrue($details->isInStock());
    }

    /**
     * @magentoDataFixture ../_files/product_simple.php
     * @magentoDataFixture ../_files/guest_quote.php
     */
    public function testAddPlainProduct(): void
    {
        $ctx = $this->context(null, $this->guestQuoteId());

        $cart = $this->backend()->addToCart($ctx, $this->productId('aiagent-simple'), 2);

        $item = $cart->find($this->productId('aiagent-simple'));
        $this->assertNotNull($item);
        $this->assertSame(2, $item->getQuantity());
    }

    /**
     * @magentoDataFixture ../_files/product_configurable.php
     * @magentoDataFixture ../_files/guest_quote.php
     */
    public function testAddVariantThroughParentWithSuperAttribute(): void
    {
        $ctx = $this->context(null, $this->guestQuoteId());
        $childId = $this->productId('aiagent-simple-s');

        $cart = $this->backend()->addToCart($ctx, $childId, 1);

        $item = $cart->find($childId);
        $this->assertNotNull($item);
        $this->assertSame($this->productId('aiagent-configurable'), $item->getVariantOf());
    }

    /**
     * @magentoDataFixture ../_files/product_configurable.php
     * @magentoDataFixture ../_files/guest_quote.php
     */
    public function testOutOfStockChildRaisesUnavailableNamingSibling(): void
    {
        $ctx = $this->context(null, $this->guestQuoteId());
        $outOfStockId = $this->productId('aiagent-simple-l');
        $inStockId = $this->productId('aiagent-simple-s');

        try {
            $this->backend()->addToCart($ctx, $outOfStockId, 1);
            $this->fail('Expected Unavailable to be thrown');
        } catch (Unavailable $exception) {
            $this->assertStringContainsString($outOfStockId, $exception->getMessage());
            $this->assertStringContainsString($inStockId, $exception->getMessage());
        }
    }

    /**
     * @magentoDataFixture ../_files/product_with_required_option.php
     */
    public function testCustomOptionProductIsFlagged(): void
    {
        $details = $this->backend()->getProductDetails($this->context(), $this->productId('aiagent-custom-option'));

        $this->assertNotNull($details);
        $this->assertTrue($details->hasRequiredCustomOptions());
    }

    public function testGuestOrdersRaiseSignInRequired(): void
    {
        $this->expectException(SignInRequired::class);

        $this->backend()->getOrders($this->context(), 10);
    }

    /**
     * @magentoDataFixture ../_files/cms_pages.php
     * @magentoConfigFixture current_store aiagent/content/policy_pages aiagent-returns,aiagent-shipping
     */
    public function testPoliciesChunkAndScore(): void
    {
        $policies = $this->backend()->searchPolicies($this->context(), 'shipping cost');

        $this->assertNotSame([], $policies);
        $this->assertStringContainsString('Shipping', $policies[0]->getTitle());
    }

    /**
     * @magentoConfigFixture current_store carriers/freeshipping/active 1
     * @magentoConfigFixture current_store carriers/freeshipping/free_shipping_subtotal 50
     */
    public function testFulfillmentFactsFromConfig(): void
    {
        $options = $this->backend()->getFulfillmentOptions($this->context(), []);
        $methods = array_map(static fn ($option) => $option->getMethod(), $options);

        $this->assertContains('shipping', $methods);
    }
}
