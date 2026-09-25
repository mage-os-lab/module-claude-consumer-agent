<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Limits;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Limits\Counters;
use MageOS\AiShoppingAssistant\Model\Limits\Exception\LimitExceeded;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class CountersTest extends TestCase
{
    private function lockManager(): LockManagerInterface&MockObject
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->method('unlock')->willReturn(true);
        return $lockManager;
    }

    /**
     * @param array<string, string> $store
     */
    private function cacheBackedBy(array &$store, int &$saves): CacheInterface&MockObject
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            }
        );
        $cache->method('save')->willReturnCallback(
            static function (string $data, string $key) use (&$store, &$saves): bool {
                $store[$key] = $data;
                $saves++;
                return true;
            }
        );
        return $cache;
    }

    /**
     * @param array<string, string> $store
     */
    private function windowKey(array $store): string
    {
        foreach (array_keys($store) as $key) {
            if (str_starts_with($key, 'aiagent_cnt_s_')) {
                return $key;
            }
        }
        $this->fail('No window counter was saved.');
    }

    public function testSessionWindowCapThrowsWithRetryAfterSixty(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key): string {
                return str_starts_with($key, 'aiagent_cnt_s_') ? '1' : '0';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $counters = new Counters($cache, $this->lockManager(), $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 1, turnsPerIpMinute: 100);

        try {
            $counters->bump('session-1', 'php-1', '10.0.0.1', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(60, $exception->getRetryAfter());
        }
    }

    public function testIpCapThrowsWithRetryAfterTwenty(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key): string {
                return str_starts_with($key, 'aiagent_cnt_ip_') ? '1' : '0';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $counters = new Counters($cache, $this->lockManager(), $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 100, turnsPerIpMinute: 1);

        try {
            $counters->bump('session-2', 'php-2', '10.0.0.2', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(20, $exception->getRetryAfter());
        }
    }

    public function testWithinLimitsDoesNotThrow(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $counters = new Counters($cache, $this->lockManager(), $logger);
        $config = new AgentConfig();

        $counters->bump('session-3', 'php-3', '10.0.0.3', $config);

        $this->addToAssertionCount(1);
    }

    public function testBumpLocksAndAlwaysReleasesTheLockAroundTheCriticalSection(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockedName = null;
        $lockManager->expects($this->once())->method('lock')->willReturnCallback(
            function (string $name, int $timeout) use (&$lockedName): bool {
                $lockedName = $name;
                return true;
            }
        );
        $lockManager->expects($this->once())->method('unlock')->willReturnCallback(
            function (string $name) use (&$lockedName): bool {
                $this->assertSame($lockedName, $name);
                return true;
            }
        );
        $counters = new Counters($cache, $lockManager, $this->createMock(LoggerInterface::class));

        $counters->bump('session-4', 'php-4', '10.0.0.8', new AgentConfig());
    }

    public function testBumpReleasesTheLockEvenWhenACapIsExceeded(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static function (string $key): string {
                return str_starts_with($key, 'aiagent_cnt_s_') ? '1' : '0';
            }
        );
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('lock')->willReturn(true);
        $lockManager->expects($this->once())->method('unlock')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $counters = new Counters($cache, $lockManager, $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 1, turnsPerIpMinute: 100);

        try {
            $counters->bump('session-5', 'php-5', '10.0.0.9', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(60, $exception->getRetryAfter());
        }
    }

    public function testAgentSessionsUnderOneBrowserSessionShareTheWindowCounter(): void
    {
        $store = [];
        $saves = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $counters = new Counters($this->cacheBackedBy($store, $saves), $this->lockManager(), $logger);
        $config = new AgentConfig();

        $counters->bump('agent-a', 'php-shared', '10.0.0.4', $config);
        $counters->bump('agent-b', 'php-shared', '10.0.0.4', $config);

        $this->assertSame('2', $store[$this->windowKey($store)]);
    }

    public function testEmptyBrowserSessionFallsBackToTheAgentSessionKey(): void
    {
        $store = [];
        $saves = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $counters = new Counters($this->cacheBackedBy($store, $saves), $this->lockManager(), $logger);

        $counters->bump('agent-c', '', '10.0.0.5', new AgentConfig());

        $expectedPrefix = 'aiagent_cnt_s_' . substr(sha1('agent-c'), 0, 24) . '_';
        $this->assertStringStartsWith($expectedPrefix, $this->windowKey($store));
    }

    public function testKeysCarryTheTimeBucketOfTheirTtl(): void
    {
        $store = [];
        $saves = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $counters = new Counters($this->cacheBackedBy($store, $saves), $this->lockManager(), $logger);

        $before = time();
        $counters->bump('agent-e', 'php-e', '10.0.0.7', new AgentConfig());
        $after = time();

        $windowBuckets = array_unique([intdiv($before, 600), intdiv($after, 600)]);
        $ipBuckets = array_unique([intdiv($before, 60), intdiv($after, 60)]);
        $windowSuffix = (int)substr(strrchr($this->windowKey($store), '_'), 1);
        $ipKey = null;
        foreach (array_keys($store) as $key) {
            if (str_starts_with($key, 'aiagent_cnt_ip_')) {
                $ipKey = $key;
            }
        }
        $this->assertNotNull($ipKey);
        $ipSuffix = (int)substr(strrchr($ipKey, '_'), 1);
        $this->assertContains($windowSuffix, $windowBuckets);
        $this->assertContains($ipSuffix, $ipBuckets);
    }

    public function testRejectedBumpDoesNotExtendTheWindow(): void
    {
        $store = [];
        $saves = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');
        $counters = new Counters($this->cacheBackedBy($store, $saves), $this->lockManager(), $logger);
        $config = new AgentConfig(turnsPerSessionWindow: 1, turnsPerIpMinute: 100);

        $counters->bump('agent-d', 'php-d', '10.0.0.6', $config);
        $windowKey = $this->windowKey($store);
        $this->assertSame('1', $store[$windowKey]);
        $savesAfterAllowedBump = $saves;

        try {
            $counters->bump('agent-d', 'php-d', '10.0.0.6', $config);
            $this->fail('Expected LimitExceeded was not thrown.');
        } catch (LimitExceeded $exception) {
            $this->assertSame(60, $exception->getRetryAfter());
        }

        $this->assertSame('1', $store[$windowKey]);
        $this->assertSame($savesAfterAllowedBump, $saves);
    }
}
