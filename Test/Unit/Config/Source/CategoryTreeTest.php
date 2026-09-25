<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Config\Source;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use MageOS\AiShoppingAssistant\Model\Config\Source\CategoryTree;
use PHPUnit\Framework\TestCase;

final class CategoryTreeTest extends TestCase
{
    private function category(int $id, string $name, string $path): Category&\PHPUnit\Framework\MockObject\MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getName')->willReturn($name);
        $category->method('getPath')->willReturn($path);
        return $category;
    }

    private function source(array $categories): CategoryTree
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addAttributeToSort')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new CategoryTree($collectionFactory);
    }

    public function testBuildsAncestorPrefixedLabelsAndSkipsUnknownAncestors(): void
    {
        $parent = $this->category(10, 'Shop by Type', '1/2/10');
        $child = $this->category(11, 'Seating', '1/2/10/11');
        $orphan = $this->category(12, 'Lounge Chairs', '1/2/99/12');

        $source = $this->source([$parent, $child, $orphan]);

        $this->assertSame(
            [
                ['value' => '12', 'label' => 'Lounge Chairs'],
                ['value' => '10', 'label' => 'Shop by Type'],
                ['value' => '11', 'label' => 'Shop by Type / Seating'],
            ],
            $source->toOptionArray()
        );
    }

    public function testCachesTheOptionArrayAcrossCalls(): void
    {
        $parent = $this->category(10, 'Shop by Type', '1/2/10');
        $collection = $this->createMock(Collection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('addAttributeToSort')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$parent]));

        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->expects($this->once())->method('create')->willReturn($collection);

        $source = new CategoryTree($collectionFactory);

        $first = $source->toOptionArray();
        $second = $source->toOptionArray();

        $this->assertSame($first, $second);
    }
}
