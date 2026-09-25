<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CategoryNameSearch;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CategoryNameSearchTest extends TestCase
{
    private function context(int $storeId = 1): SessionContext
    {
        return new SessionContext('session-1', null, 1, $storeId, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeManager(int $rootCategoryId): StoreManagerInterface&MockObject
    {
        $store = $this->createMock(Store::class);
        $store->method('getRootCategoryId')->willReturn($rootCategoryId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function category(int $id, string $name, string $path): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getPath')->willReturn($path);
        $category->method('getLevel')->willReturn(2);
        $category->method('getPosition')->willReturn(0);
        return $category;
    }

    private function rootCollection(Category $root): CategoryCollection&MockObject
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($root);
        return $collection;
    }

    private function searchCollection(array $items): CategoryCollection&MockObject
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIsActiveFilter')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addAttributeToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        return $collection;
    }

    private function ancestorCollection(array $items): CategoryCollection&MockObject
    {
        $collection = $this->createMock(CategoryCollection::class);
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addIdFilter')->willReturnSelf();
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        return $collection;
    }

    public function testProductCountReadsTheStoreCategoryProductIndexTable(): void
    {
        $root = $this->category(2, 'Default Category', '1/2');
        $seating = $this->category(20, 'Seating', '1/2/20');

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->rootCollection($root),
            $this->searchCollection([$seating]),
            $this->ancestorCollection([])
        );

        $select = $this->createMock(Select::class);
        $select->expects($this->once())->method('from')->with(
            'catalog_category_product_index_store1',
            $this->anything()
        )->willReturnSelf();
        $whereCalls = [];
        $select->method('where')->willReturnCallback(
            function (string $condition, $value) use ($select, &$whereCalls): Select {
                $whereCalls[] = [$condition, $value];
                return $select;
            }
        );
        $select->method('group')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchPairs')->willReturn([20 => 3]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);

        $tableMaintainer = $this->createMock(TableMaintainer::class);
        $tableMaintainer->expects($this->once())
            ->method('getMainTable')
            ->with(1)
            ->willReturn('catalog_category_product_index_store1');

        $search = new CategoryNameSearch(
            $collectionFactory,
            $this->storeManager(2),
            $resourceConnection,
            $tableMaintainer
        );

        $matches = $search->search($this->context(1), 'seating', 5);

        $this->assertSame(3, $matches[0]->getProductCount());
        $this->assertNotEmpty(array_filter(
            $whereCalls,
            static fn (array $call): bool => str_contains($call[0], 'store_id') && $call[1] === 1
        ));
    }

    public function testAncestorNamesCollectionIsScopedToTheStore(): void
    {
        $root = $this->category(2, 'Default Category', '1/2');
        $chairs = $this->category(30, 'Chairs', '1/2/10/30');
        $seatingAncestor = $this->category(10, 'Seating', '1/2/10');

        $ancestorCollection = $this->createMock(CategoryCollection::class);
        $ancestorCollection->expects($this->once())->method('setStoreId')->with(7);
        $ancestorCollection->method('addAttributeToSelect')->willReturnSelf();
        $ancestorCollection->method('addIdFilter')->willReturnSelf();
        $ancestorCollection->method('getIterator')->willReturn(new \ArrayIterator([$seatingAncestor]));

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->method('create')->willReturnOnConsecutiveCalls(
            $this->rootCollection($root),
            $this->searchCollection([$chairs]),
            $ancestorCollection
        );

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchPairs')->willReturn([]);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);

        $tableMaintainer = $this->createMock(TableMaintainer::class);
        $tableMaintainer->method('getMainTable')->willReturn('catalog_category_product_index_store7');

        $search = new CategoryNameSearch(
            $collectionFactory,
            $this->storeManager(2),
            $resourceConnection,
            $tableMaintainer
        );

        $matches = $search->search($this->context(7), 'chairs', 5);

        $this->assertSame(['Seating'], $matches[0]->getPath());
    }
}
