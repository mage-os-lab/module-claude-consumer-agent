<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AiShoppingAssistant\Console\Command\UsageReport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UsageReportTest extends TestCase
{
    public function testDailyAndTotalsTablesArePrintedWithComputedAverages(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            [
                'day' => '2026-09-01',
                'turns' => '2',
                'input_tokens' => '1000',
                'output_tokens' => '500',
                'cache_creation_input_tokens' => '100',
                'cache_read_input_tokens' => '200',
            ],
        ]);
        $connection->method('fetchRow')->willReturn([
            'turns' => '4',
            'sessions' => '2',
            'input_tokens' => '2000',
            'output_tokens' => '1000',
            'cache_creation_input_tokens' => '200',
            'cache_read_input_tokens' => '400',
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $tester = new CommandTester(new UsageReport($resourceConnection));
        $exitCode = $tester->execute(['--days' => '7']);

        $this->assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('2026-09-01', $display);
        $this->assertStringContainsString('900.00', $display);
        $this->assertStringContainsString('1800.00', $display);
    }

    public function testStoreOptionFiltersByStoreId(): void
    {
        $whereConditions = [];
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition) use (&$whereConditions, $select) {
                $whereConditions[] = $condition;
                return $select;
            }
        );

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchRow')->willReturn([]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $tester = new CommandTester(new UsageReport($resourceConnection));
        $tester->execute(['--days' => '7', '--store' => '2']);

        $this->assertContains('store_id = ?', $whereConditions);
    }

    public function testZeroTotalsAvoidDivisionByZero(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('fetchRow')->willReturn([
            'turns' => '0',
            'sessions' => '0',
            'input_tokens' => '0',
            'output_tokens' => '0',
            'cache_creation_input_tokens' => '0',
            'cache_read_input_tokens' => '0',
        ]);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $tester = new CommandTester(new UsageReport($resourceConnection));
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0.00', $tester->getDisplay());
    }
}
