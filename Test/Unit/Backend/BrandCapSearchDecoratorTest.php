<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use MageOS\ClaudeConsumerAgent\Api\Backend\SearchProviderInterface;
use MageOS\ClaudeConsumerAgent\Api\Data\SearchFiltersInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\BrandCapSearchDecorator;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use MageOS\ClaudeConsumerAgent\Model\Data\SearchFilters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BrandCapSearchDecoratorTest extends TestCase
{
    private function context(int $storeId = 2): SessionContext
    {
        return new SessionContext('session-1', null, 1, $storeId, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function productCollectionFactory(array $brandsById = []): ProductCollectionFactory&MockObject
    {
        $products = [];
        foreach ($brandsById as $id => $brand) {
            $product = $this->createMock(MagentoProduct::class);
            $product->method('getId')->willReturn($id);
            $product->method('getAttributeText')->willReturn($brand);
            $products[] = $product;
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($products));

        $factory = $this->createMock(ProductCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    private function wrappedProvider(array $ids): SearchProviderInterface&MockObject
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->method('search')->willReturn($ids);
        return $provider;
    }

    private function build(
        SearchProviderInterface $provider,
        array $brandsById = []
    ): BrandCapSearchDecorator {
        return new BrandCapSearchDecorator($provider, $this->productCollectionFactory($brandsById));
    }

    public function testADominantBrandIsTrimmedAndBackfilledFromTheRemainingRankedIds(): void
    {
        $provider = $this->wrappedProvider([1, 2, 3, 4, 5, 6]);
        $decorator = $this->build($provider, [
            1 => 'BrandA',
            2 => 'BrandA',
            3 => 'BrandA',
            4 => 'BrandA',
            5 => 'BrandB',
            6 => 'BrandC',
        ]);

        $ids = $decorator->search($this->context(), 'office chair', null, 4);

        $this->assertSame([1, 5, 6, 2], $ids);
    }

    public function testRelevanceOrderHoldsWhenNoBrandExceedsTheCap(): void
    {
        $provider = $this->wrappedProvider([10, 20, 30, 40, 50]);
        $decorator = $this->build($provider, [
            10 => 'BrandA',
            20 => 'BrandB',
            30 => 'BrandC',
            40 => 'BrandD',
            50 => 'BrandE',
        ]);

        $ids = $decorator->search($this->context(), 'widget', null, 5);

        $this->assertSame([10, 20, 30, 40, 50], $ids);
    }

    public function testABrandNamedQueryIsNotCapped(): void
    {
        $provider = $this->wrappedProvider([1, 2, 3, 4]);
        $decorator = $this->build($provider, [
            1 => 'Acme',
            2 => 'Acme',
            3 => 'Acme',
            4 => 'Acme',
        ]);

        $ids = $decorator->search($this->context(), 'Acme desk lamp', null, 4);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testBrandsAreResolvedWithASingleCollectionQueryForTheWholeCandidateList(): void
    {
        $provider = $this->wrappedProvider([1, 2, 3, 4, 5]);
        $productCollectionFactory = $this->productCollectionFactory([
            1 => 'BrandA',
            2 => 'BrandB',
            3 => 'BrandC',
            4 => 'BrandD',
            5 => 'BrandE',
        ]);
        $productCollectionFactory->expects($this->once())->method('create');

        $decorator = new BrandCapSearchDecorator($provider, $productCollectionFactory);
        $decorator->search($this->context(), 'office chair', null, 5);
    }

    public function testAsksTheWrappedProviderForMoreThanTheRequestedLimitToBackfillAfterCapping(): void
    {
        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with($this->anything(), 'office chair', null, 12)
            ->willReturn([1, 2, 3, 4, 5, 6]);

        $decorator = $this->build($provider, [
            1 => 'BrandA',
            2 => 'BrandA',
            3 => 'BrandA',
            4 => 'BrandA',
            5 => 'BrandB',
            6 => 'BrandC',
        ]);

        $ids = $decorator->search($this->context(), 'office chair', null, 4);

        $this->assertCount(4, $ids);
    }

    public function testBestSellersSortIsPassedThroughWithoutOverfetchOrCapping(): void
    {
        $ctx = $this->context();
        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with($ctx, 'office chair', $filters, 4)
            ->willReturn([1, 2, 3, 4]);

        $productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $productCollectionFactory->expects($this->never())->method('create');

        $decorator = new BrandCapSearchDecorator($provider, $productCollectionFactory);
        $ids = $decorator->search($ctx, 'office chair', $filters, 4);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testEmptyQueryIsPassedThroughWithoutOverfetchOrCapping(): void
    {
        $ctx = $this->context();
        $filters = SearchFilters::fromArray(['category_id' => 175]);

        $provider = $this->createMock(SearchProviderInterface::class);
        $provider->expects($this->once())
            ->method('search')
            ->with($ctx, '', $filters, 10)
            ->willReturn([1, 2]);

        $productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $productCollectionFactory->expects($this->never())->method('create');

        $decorator = new BrandCapSearchDecorator($provider, $productCollectionFactory);
        $ids = $decorator->search($ctx, '', $filters, 10);

        $this->assertSame([1, 2], $ids);
    }

    public function testACustomSearchProviderBoundToTheInterfaceStillGetsItsResultsCapped(): void
    {
        $thirdPartyProvider = new class implements SearchProviderInterface {
            public function search(
                SessionContext $ctx,
                string $query,
                ?SearchFiltersInterface $filters,
                int $limit
            ): array {
                return [1, 2, 3, 4, 5, 6];
            }
        };

        $decorator = $this->build($thirdPartyProvider, [
            1 => 'BrandA',
            2 => 'BrandA',
            3 => 'BrandA',
            4 => 'BrandA',
            5 => 'BrandB',
            6 => 'BrandC',
        ]);

        $ids = $decorator->search($this->context(), 'office chair', null, 4);

        $this->assertSame([1, 5, 6, 2], $ids);
    }
}
