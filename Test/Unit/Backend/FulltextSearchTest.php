<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Backend;

use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\CategorySearchResultsInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\DocumentInterface;
use Magento\Framework\Api\Search\SearchCriteria;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteria as PlainSearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder as PlainSearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Api\Backend\BestsellerRankInterface;
use MageOS\ClaudeConsumerAgent\Model\Agent\SessionContext;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\AllowedCategories;
use MageOS\ClaudeConsumerAgent\Model\Backend\Provider\FulltextSearch;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Data\PageContext;
use MageOS\ClaudeConsumerAgent\Model\Data\SearchFilters;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FulltextSearchTest extends TestCase
{
    private array $filterCalls = [];
    private array $sortOrderCalls = [];
    private array $pageSizeCalls = [];

    private function context(int $storeId = 2): SessionContext
    {
        return new SessionContext('session-1', null, 1, $storeId, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeConfig(array $allowedCategories = []): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'ai_integration/aiagent/content/allowed_categories'
                ? implode(',', $allowedCategories)
                : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function category(int $id, string $path): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getPath')->willReturn($path);
        return $category;
    }

    private function allowedCategories(
        array $allowedCategories = [],
        array $categories = [],
        ?CategoryCollectionFactory $collectionFactory = null
    ): AllowedCategories {
        if ($collectionFactory === null) {
            $collection = $this->createMock(CategoryCollection::class);
            $collection->method('setStoreId')->willReturnSelf();
            $collection->method('addAttributeToSelect')->willReturnSelf();
            $collection->method('addIdFilter')->willReturnSelf();
            $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

            $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
            $collectionFactory->method('create')->willReturn($collection);
        }

        return new AllowedCategories($collectionFactory, $this->storeConfig($allowedCategories));
    }

    private function categoryListResolving(string $name, int $categoryId): CategoryListInterface&MockObject
    {
        $category = $this->createMock(CategoryInterface::class);
        $category->method('getId')->willReturn($categoryId);
        $results = $this->createMock(CategorySearchResultsInterface::class);
        $results->method('getItems')->willReturn([$category]);

        $categoryList = $this->createMock(CategoryListInterface::class);
        $categoryList->method('getList')->willReturn($results);
        return $categoryList;
    }

    private function filterBuilder(): FilterBuilder&MockObject
    {
        $builder = $this->createMock(FilterBuilder::class);
        $current = ['field' => null, 'value' => null];
        $builder->method('setField')->willReturnCallback(function (string $field) use ($builder, &$current): FilterBuilder {
            $current['field'] = $field;
            return $builder;
        });
        $builder->method('setValue')->willReturnCallback(function ($value) use ($builder, &$current): FilterBuilder {
            $current['value'] = $value;
            return $builder;
        });
        $builder->method('create')->willReturnCallback(function () use (&$current): Filter {
            $this->filterCalls[] = $current;
            return (new Filter())->setField($current['field'])->setValue($current['value']);
        });
        return $builder;
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder&MockObject
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnCallback(
            function (int $pageSize) use ($builder): SearchCriteriaBuilder {
                $this->pageSizeCalls[] = $pageSize;
                return $builder;
            }
        );
        $builder->method('addSortOrder')->willReturnCallback(
            function (string $field, string $direction) use ($builder): SearchCriteriaBuilder {
                $this->sortOrderCalls[] = ['field' => $field, 'direction' => $direction];
                return $builder;
            }
        );
        $builder->method('create')->willReturnCallback(static fn (): SearchCriteria => new SearchCriteria());
        return $builder;
    }

    private function searchCriteriaBuilderFactory(
        SearchCriteriaBuilder $builder
    ): SearchCriteriaBuilderFactory&MockObject {
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        return $factory;
    }

    private function categorySearchCriteriaBuilder(): PlainSearchCriteriaBuilder&MockObject
    {
        $builder = $this->createMock(PlainSearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(PlainSearchCriteria::class));
        return $builder;
    }

    private function searchResult(array $ids): SearchResultInterface&MockObject
    {
        $documents = array_map(
            function (int $id): DocumentInterface&MockObject {
                $document = $this->createMock(DocumentInterface::class);
                $document->method('getId')->willReturn($id);
                return $document;
            },
            $ids
        );
        $result = $this->createMock(SearchResultInterface::class);
        $result->method('getItems')->willReturn($documents);
        return $result;
    }

    private function defaultStoreManager(): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function passthroughBestsellerRank(): BestsellerRankInterface&MockObject
    {
        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->method('rank')->willReturnArgument(0);
        return $bestsellerRank;
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

    private function build(array $overrides = []): array
    {
        $filterBuilder = $overrides['filterBuilder'] ?? $this->filterBuilder();
        $searchCriteriaBuilder = $overrides['searchCriteriaBuilder'] ?? $this->searchCriteriaBuilder();
        $searchCriteriaBuilderFactory = $overrides['searchCriteriaBuilderFactory']
            ?? $this->searchCriteriaBuilderFactory($searchCriteriaBuilder);
        $search = $overrides['search'] ?? $this->createMock(SearchInterface::class);
        $storeManager = $overrides['storeManager'] ?? $this->defaultStoreManager();
        $bestsellerRank = $overrides['bestsellerRank'] ?? $this->passthroughBestsellerRank();
        $categoryList = $overrides['categoryList'] ?? $this->createMock(CategoryListInterface::class);
        $allowedCategories = $overrides['allowedCategories'] ?? $this->allowedCategories();
        $productCollectionFactory = $overrides['productCollectionFactory'] ?? $this->productCollectionFactory();

        $provider = new FulltextSearch(
            $searchCriteriaBuilderFactory,
            $filterBuilder,
            $search,
            $categoryList,
            $this->categorySearchCriteriaBuilder(),
            $storeManager,
            $allowedCategories,
            $bestsellerRank,
            $productCollectionFactory
        );

        return [$provider, $search, $storeManager, $bestsellerRank];
    }

    public function testSetsQuickSearchContainerRequestNameOnTheCriteriaObject(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->with($this->callback(
                static fn (SearchCriteria $criteria): bool => $criteria->getRequestName() === 'quick_search_container'
            ))
            ->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);
    }

    public function testAddsSearchTermAndVisibilityFilters(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $this->assertContains(['field' => 'search_term', 'value' => 'widget'], $this->filterCalls);
        $this->assertContains(
            ['field' => 'visibility', 'value' => [Visibility::VISIBILITY_IN_SEARCH, Visibility::VISIBILITY_BOTH]],
            $this->filterCalls
        );
    }

    public function testAddsPriceFiltersWhenGiven(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['min_price' => 10.0, 'max_price' => 50.0]);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'price.from', 'value' => '10'], $this->filterCalls);
        $this->assertContains(['field' => 'price.to', 'value' => '50'], $this->filterCalls);
    }

    public function testOmitsPriceFiltersWhenNotGiven(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $fields = array_column($this->filterCalls, 'field');
        $this->assertNotContains('price.from', $fields);
        $this->assertNotContains('price.to', $fields);
    }

    public function testSwapsToTheContextStoreAndRestoresThePreviousStoreAfterSearch(): void
    {
        $previousStore = $this->createMock(StoreInterface::class);
        $previousStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($previousStore);

        $setCurrentStoreCalls = [];
        $storeManager->method('setCurrentStore')->willReturnCallback(
            function (int $storeId) use (&$setCurrentStoreCalls): void {
                $setCurrentStoreCalls[] = $storeId;
            }
        );

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search, 'storeManager' => $storeManager]);

        $provider->search($this->context(5), 'widget', null, 10);

        $this->assertSame([5, 1], $setCurrentStoreCalls);
    }

    public function testRestoresThePreviousStoreEvenWhenSearchThrows(): void
    {
        $previousStore = $this->createMock(StoreInterface::class);
        $previousStore->method('getId')->willReturn(1);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($previousStore);

        $setCurrentStoreCalls = [];
        $storeManager->method('setCurrentStore')->willReturnCallback(
            function (int $storeId) use (&$setCurrentStoreCalls): void {
                $setCurrentStoreCalls[] = $storeId;
            }
        );

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willThrowException(new \RuntimeException('search backend unavailable'));

        [$provider] = $this->build(['search' => $search, 'storeManager' => $storeManager]);

        try {
            $provider->search($this->context(5), 'widget', null, 10);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('search backend unavailable', $exception->getMessage());
        }

        $this->assertSame([5, 1], $setCurrentStoreCalls);
    }

    public function testReturnsDocumentIdsFromTheSearchResult(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20]));

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), 'widget', null, 10);

        $this->assertSame([10, 20], $ids);
    }

    public function testAddsARelevanceDescendingSortOrder(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $provider->search($this->context(), 'widget', null, 10);

        $this->assertSame([['field' => 'relevance', 'direction' => 'DESC']], $this->sortOrderCalls);
    }

    public function testKeywordQueryWithPriceAscSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['sort' => 'price_asc']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'ASC']], $this->sortOrderCalls);
    }

    public function testKeywordQueryWithPriceDescSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['sort' => 'price_desc']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'DESC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdUsesCatalogViewContainerRequestName(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->with($this->callback(
                static fn (SearchCriteria $criteria): bool => $criteria->getRequestName() === 'catalog_view_container'
            ))
            ->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);
    }

    public function testEmptyQueryWithCategoryIdSortsByPositionAscendingByDefault(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'position', 'direction' => 'ASC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdAndPriceAscSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'price_asc']);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'ASC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdAndPriceDescSortMapsToThePriceField(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'price_desc']);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertSame([['field' => 'price', 'direction' => 'DESC']], $this->sortOrderCalls);
    }

    public function testEmptyQueryWithCategoryIdDoesNotAddASearchTermFilter(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);

        $fields = array_column($this->filterCalls, 'field');
        $this->assertNotContains('search_term', $fields);
        $this->assertContains(['field' => 'category_ids', 'value' => ['175']], $this->filterCalls);
    }

    public function testEmptyQueryWithNoCategoryReturnsEmptyWithoutCallingSearch(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->never())->method('search');

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), '', null, 10);

        $this->assertSame([], $ids);
    }

    public function testCategoryIdFilterTakesPrecedenceOverCategoryName(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        $categoryList = $this->createMock(CategoryListInterface::class);
        $categoryList->expects($this->never())->method('getList');

        $provider = new FulltextSearch(
            $this->searchCriteriaBuilderFactory($this->searchCriteriaBuilder()),
            $this->filterBuilder(),
            $search,
            $categoryList,
            $this->categorySearchCriteriaBuilder(),
            $this->defaultStoreManager(),
            $this->allowedCategories(),
            $this->passthroughBestsellerRank(),
            $this->productCollectionFactory()
        );

        $filters = SearchFilters::fromArray(['category_id' => 175, 'category' => 'Seating']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['175']], $this->filterCalls);
    }

    public function testRequestedCategoryInsideTheAllowlistIsUsedAsTheFilter(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build([
            'search' => $search,
            'allowedCategories' => $this->allowedCategories([10, 20]),
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 20]);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['20']], $this->filterCalls);
    }

    public function testRequestedDescendantOfAnAllowedCategoryIsUsedAsTheFilter(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build([
            'search' => $search,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(55, '1/2/10/55')]),
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 55]);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['55']], $this->filterCalls);
    }

    public function testRequestedCategoryOutsideTheAllowlistFallsBackToTheAllowlist(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build([
            'search' => $search,
            'allowedCategories' => $this->allowedCategories([10, 20], [$this->category(99, '1/2/5/99')]),
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 99]);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['10', '20']], $this->filterCalls);
        $this->assertNotContains(['field' => 'category_ids', 'value' => ['99']], $this->filterCalls);
    }

    public function testResolvedCategoryNameOutsideTheAllowlistFallsBackToTheAllowlist(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build([
            'search' => $search,
            'categoryList' => $this->categoryListResolving('Clearance', 99),
            'allowedCategories' => $this->allowedCategories([10], [$this->category(99, '1/2/5/99')]),
        ]);

        $filters = SearchFilters::fromArray(['category' => 'Clearance']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['10']], $this->filterCalls);
        $this->assertNotContains(['field' => 'category_ids', 'value' => ['99']], $this->filterCalls);
    }

    public function testEmptyAllowlistKeepsTheRequestedCategoryWithoutLoadingIt(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->expects($this->never())->method('create');

        [$provider] = $this->build([
            'search' => $search,
            'allowedCategories' => $this->allowedCategories([], [], $collectionFactory),
        ]);

        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $provider->search($this->context(), '', $filters, 10);

        $this->assertContains(['field' => 'category_ids', 'value' => ['175']], $this->filterCalls);
    }

    public function testEachSearchCallGetsAFreshSearchCriteriaBuilder(): void
    {
        $firstBuilder = $this->searchCriteriaBuilder();
        $secondBuilder = $this->searchCriteriaBuilder();
        $factory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $factory->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($firstBuilder, $secondBuilder);

        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search, 'searchCriteriaBuilderFactory' => $factory]);

        $provider->search($this->context(), 'first', null, 10);
        $provider->search($this->context(), 'second', null, 10);

        $this->assertNotSame($firstBuilder, $secondBuilder);
    }

    public function testBestSellersSortWidensThePageSizeToFiveTimesTheLimit(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);
        $provider->search($this->context(), 'widget', $filters, 10);

        $this->assertSame([50], $this->pageSizeCalls);
    }

    public function testBestSellersSortCapsThePageSizeAtSixty(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $filters = SearchFilters::fromArray(['category_id' => 175, 'sort' => 'best_sellers']);
        $provider->search($this->context(), '', $filters, 20);

        $this->assertSame([60], $this->pageSizeCalls);
    }

    public function testBestSellersSortRanksResultsAndSlicesToTheRequestedLimit(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20, 30, 40, 50]));

        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->expects($this->once())
            ->method('rank')
            ->with([10, 20, 30, 40, 50], 2)
            ->willReturn([50, 40, 30, 20, 10]);

        [$provider] = $this->build(['search' => $search, 'bestsellerRank' => $bestsellerRank]);

        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);
        $ids = $provider->search($this->context(), 'widget', $filters, 2);

        $this->assertSame([50, 40], $ids);
    }

    public function testNonBestSellersSortNeverCallsTheBestsellerRank(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20]));

        $bestsellerRank = $this->createMock(BestsellerRankInterface::class);
        $bestsellerRank->expects($this->never())->method('rank');

        [$provider] = $this->build(['search' => $search, 'bestsellerRank' => $bestsellerRank]);

        $provider->search($this->context(), 'widget', null, 10);
    }

    public function testADominantBrandIsTrimmedAndBackfilledFromTheRemainingRankedIds(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([1, 2, 3, 4, 5, 6]));

        $productCollectionFactory = $this->productCollectionFactory([
            1 => 'BrandA',
            2 => 'BrandA',
            3 => 'BrandA',
            4 => 'BrandA',
            5 => 'BrandB',
            6 => 'BrandC',
        ]);

        [$provider] = $this->build(['search' => $search, 'productCollectionFactory' => $productCollectionFactory]);

        $ids = $provider->search($this->context(), 'office chair', null, 4);

        $this->assertSame([1, 5, 6, 2], $ids);
    }

    public function testRelevanceOrderHoldsWhenNoBrandExceedsTheCap(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([10, 20, 30, 40, 50]));

        $productCollectionFactory = $this->productCollectionFactory([
            10 => 'BrandA',
            20 => 'BrandB',
            30 => 'BrandC',
            40 => 'BrandD',
            50 => 'BrandE',
        ]);

        [$provider] = $this->build(['search' => $search, 'productCollectionFactory' => $productCollectionFactory]);

        $ids = $provider->search($this->context(), 'widget', null, 5);

        $this->assertSame([10, 20, 30, 40, 50], $ids);
    }

    public function testABrandNamedQueryIsNotCapped(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([1, 2, 3, 4]));

        $productCollectionFactory = $this->productCollectionFactory([
            1 => 'Acme',
            2 => 'Acme',
            3 => 'Acme',
            4 => 'Acme',
        ]);

        [$provider] = $this->build(['search' => $search, 'productCollectionFactory' => $productCollectionFactory]);

        $ids = $provider->search($this->context(), 'Acme desk lamp', null, 4);

        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testBrandsAreResolvedWithASingleCollectionQueryForTheWholeCandidateList(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->method('search')->willReturn($this->searchResult([1, 2, 3, 4, 5]));

        $productCollectionFactory = $this->productCollectionFactory([
            1 => 'BrandA',
            2 => 'BrandB',
            3 => 'BrandC',
            4 => 'BrandD',
            5 => 'BrandE',
        ]);
        $productCollectionFactory->expects($this->once())->method('create');

        [$provider] = $this->build(['search' => $search, 'productCollectionFactory' => $productCollectionFactory]);

        $provider->search($this->context(), 'office chair', null, 5);
    }

    public function testAnEmptyFirstResultRetriesOnceWithTheLongestWord(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls($this->searchResult([]), $this->searchResult([99]));

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), 'hanna morrison chair', null, 10);

        $this->assertSame([99], $ids);
        $this->assertContains(['field' => 'search_term', 'value' => 'hanna morrison chair'], $this->filterCalls);
        $this->assertContains(['field' => 'search_term', 'value' => 'morrison'], $this->filterCalls);
    }

    public function testANonEmptyFirstResultDoesNotRetry(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->willReturn($this->searchResult([1, 2]));

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), 'hanna morrison chair', null, 10);

        $this->assertSame([1, 2], $ids);
    }

    public function testASingleWordQueryDoesNotRetry(): void
    {
        $search = $this->createMock(SearchInterface::class);
        $search->expects($this->once())
            ->method('search')
            ->willReturn($this->searchResult([]));

        [$provider] = $this->build(['search' => $search]);

        $ids = $provider->search($this->context(), 'widget', null, 10);

        $this->assertSame([], $ids);
    }
}
