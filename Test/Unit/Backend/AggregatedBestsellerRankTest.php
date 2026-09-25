<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\AggregatedBestsellerRank;
use PHPUnit\Framework\TestCase;

final class AggregatedBestsellerRankTest extends TestCase
{
    private function buildRank(array $superLinkRows, array $yearlyPairs): AggregatedBestsellerRank
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchAll')->willReturn($superLinkRows);
        $adapter->method('fetchPairs')->willReturn($yearlyPairs);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new AggregatedBestsellerRank($resourceConnection);
    }

    public function testRankReturnsEmptyArrayWithoutQueryingWhenGivenNoProductIds(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->never())->method('select');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);

        $rank = new AggregatedBestsellerRank($resourceConnection);

        $this->assertSame([], $rank->rank([], 1));
    }

    public function testRanksCandidatesByQtyOrderedDescending(): void
    {
        $rank = $this->buildRank([], [1 => 5, 2 => 20, 3 => 10]);

        $this->assertSame([2, 3, 1], $rank->rank([1, 2, 3], 1));
    }

    public function testFoldsConfigurableChildQuantitiesIntoTheirParent(): void
    {
        $rank = $this->buildRank(
            [
                ['parent_id' => 10, 'product_id' => 11],
                ['parent_id' => 10, 'product_id' => 12],
            ],
            [11 => 5, 12 => 7, 20 => 3]
        );

        $this->assertSame([10, 20], $rank->rank([10, 20], 1));
    }

    public function testIdsWithNoSalesAreLastInTheirOriginalOrder(): void
    {
        $rank = $this->buildRank([], [1 => 5, 3 => 5]);

        $this->assertSame([1, 3, 2, 4], $rank->rank([1, 2, 3, 4], 1));
    }

    public function testKeepsOriginalOrderForTiedQuantities(): void
    {
        $rank = $this->buildRank([], [5 => 10, 6 => 10]);

        $this->assertSame([5, 6], $rank->rank([5, 6], 1));
    }

    public function testDeduplicatesRepeatedCandidateIds(): void
    {
        $rank = $this->buildRank([], [7 => 4]);

        $this->assertSame([7], $rank->rank([7, 7], 1));
    }
}
