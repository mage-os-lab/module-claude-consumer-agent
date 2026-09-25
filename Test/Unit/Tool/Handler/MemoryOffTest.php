<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\MemoryOff;
use PHPUnit\Framework\TestCase;

final class MemoryOffTest extends TestCase
{
    public function testAlwaysReturnsTheNoMemoryLine(): void
    {
        $page = $this->createMock(PageContextInterface::class);
        $context = new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
        $handler = new MemoryOff();

        $outcome = $handler->handle([], $context, new SessionState(), new AgentConfig());

        $this->assertFalse($outcome->isError);
        $this->assertSame('This store does not keep memory between conversations.', $outcome->resultText);
        $this->assertSame([], $outcome->events);
        $this->assertSame([], $outcome->products);
    }
}
