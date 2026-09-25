<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Cron\Retention;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    private function buildStoreConfig(callable $retentionDaysByStoreId): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scopeType = '', $storeId = null) use ($retentionDaysByStoreId) {
                if ($path === 'ai_integration/aiagent/privacy/retention_days') {
                    return $retentionDaysByStoreId((int)$storeId);
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    /**
     * @param int[] $storeIds
     */
    private function storeManagerWithStores(array $storeIds): StoreManagerInterface
    {
        $stores = array_map(
            function (int $storeId): StoreInterface {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn($storeId);
                return $store;
            },
            $storeIds
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        return $storeManager;
    }

    public function testExecuteDeletesOlderSessionsPerStoreAndLogsEachCount(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->exactly(2))
            ->method('deleteOlderThan')
            ->willReturnOnConsecutiveCalls(7, 3);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('info');

        $storeConfig = $this->buildStoreConfig(static fn (int $storeId): int => 30);
        $storeManager = $this->storeManagerWithStores([1, 2]);
        $retention = new Retention($storeConfig, $resource, $storeManager, $logger);
        $retention->execute();
    }

    public function testExecuteSkipsStoresWhereRetentionDaysIsZeroOrLess(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->never())->method('deleteOlderThan');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $storeConfig = $this->buildStoreConfig(static fn (int $storeId): int => 0);
        $storeManager = $this->storeManagerWithStores([1]);
        $retention = new Retention($storeConfig, $resource, $storeManager, $logger);
        $retention->execute();
    }

    public function testExecutePassesEachStoreIdToDeleteOlderThan(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class), 5)
            ->willReturn(1);
        $logger = $this->createMock(LoggerInterface::class);

        $storeConfig = $this->buildStoreConfig(static fn (int $storeId): int => 30);
        $storeManager = $this->storeManagerWithStores([5]);
        $retention = new Retention($storeConfig, $resource, $storeManager, $logger);
        $retention->execute();
    }

    public function testExecuteDeletesOnlyForStoresWithTheirOwnRetentionEnabled(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class), 2)
            ->willReturn(9);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $storeConfig = $this->buildStoreConfig(static fn (int $storeId): int => $storeId === 2 ? 30 : 0);
        $storeManager = $this->storeManagerWithStores([1, 2]);
        $retention = new Retention($storeConfig, $resource, $storeManager, $logger);
        $retention->execute();
    }
}
