<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\SkuMatcher;
use PHPUnit\Framework\TestCase;

final class SkuMatcherTest extends TestCase
{
    public function testAnEmptyCandidateListReturnsNullWithoutAQuery(): void
    {
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->expects($this->never())->method('getConnection');

        $matcher = new SkuMatcher($resourceConnection);

        $this->assertNull($matcher->firstExisting([], 1));
        $this->assertNull($matcher->firstExisting(['', ''], 1));
    }

    public function testBindsTheCandidatesIntoOneInQueryAndReturnsTheStoredSku(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects($this->once())
            ->method('from')
            ->with('prefix_catalog_product_entity', ['sku'])
            ->willReturnSelf();
        $select->expects($this->once())->method('where')->with('sku IN (?)', ['24-mb01', 'WS12'])->willReturnSelf();
        $select->expects($this->once())->method('limit')->with(1)->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->expects($this->once())->method('fetchOne')->with($select)->willReturn('24-MB01');

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $resourceConnection->method('getTableName')->willReturnCallback(
            static fn (string $table): string => 'prefix_' . $table
        );

        $matcher = new SkuMatcher($resourceConnection);

        $this->assertSame('24-MB01', $matcher->firstExisting(['24-mb01', 'WS12'], 1));
    }

    public function testNoMatchingRowReturnsNull(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);
        $adapter->method('fetchOne')->willReturn(false);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $matcher = new SkuMatcher($resourceConnection);

        $this->assertNull($matcher->firstExisting(['nope-1'], 1));
    }
}
