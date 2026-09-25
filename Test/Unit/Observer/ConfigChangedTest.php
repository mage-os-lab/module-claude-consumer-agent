<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Observer;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use MageOS\AiShoppingAssistant\Observer\ConfigChanged;
use PHPUnit\Framework\TestCase;

final class ConfigChangedTest extends TestCase
{
    public function testExecuteCleansTheAiagentCacheTag(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('clean')
            ->with(['AIAGENT']);
        $observerClass = new ConfigChanged($cache);
        $observerClass->execute(new Observer());
    }
}
