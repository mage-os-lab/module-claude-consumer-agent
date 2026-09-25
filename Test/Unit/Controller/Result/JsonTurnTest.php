<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Controller\Result;

use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Controller\Result\JsonTurn;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator;
use MageOS\AiShoppingAssistant\Model\Limits\SlotHandle;
use MageOS\AiShoppingAssistant\Model\Session\Binding;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class JsonTurnTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Orchestrator::class)) {
            $this->markTestSkipped('Turn\\Orchestrator is not present on disk yet (task T9 has not landed).');
        }
        if ((new \ReflectionClass(Orchestrator::class))->isFinal()) {
            $this->markTestSkipped('Turn\\Orchestrator is declared final and cannot be mocked by PHPUnit 9.6.');
        }
    }

    private function binding(): Binding
    {
        $page = $this->createMock(PageContextInterface::class);
        $context = new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
        return new Binding('sess-1', ['turns' => 0], new SessionState(), false, 0, $context);
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    /**
     * @param Event[] $events
     */
    private function generatorOf(array $events): \Generator
    {
        foreach ($events as $event) {
            yield $event;
        }
    }

    private function throwingGenerator(): \Generator
    {
        yield Event::textDelta('partial');
        throw new \RuntimeException('boom');
    }

    private function throwingGeneratorWithApiKeyInMessage(): \Generator
    {
        yield Event::textDelta('partial');
        throw new \RuntimeException('bad key sk-ant-abc123XYZ used');
    }

    private function buildResult(
        Binding $binding,
        string $message,
        SessionContext $context,
        ?SlotHandle $slot,
        ?Event $busyEvent,
        Orchestrator $orchestrator,
        LoggerInterface $logger
    ): JsonTurn {
        $result = new JsonTurn($orchestrator, $logger);
        if ($busyEvent !== null) {
            $result->setBusy($busyEvent);
        }
        return $result->setTurn($binding, $message, $context, $slot);
    }

    public function testTwoEventsAreCollectedIntoTheJsonBodyAndSlotIsReleased(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $events = [Event::textDelta('hi'), Event::turnComplete('end_turn', [], 10, 0)];
        $orchestrator->method('streamTurn')->willReturn($this->generatorOf($events));

        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->once())->method('setNoCacheHeaders');
        $response->expects($this->once())->method('setMetadata')->with('NotCacheable', true);
        $captured = null;
        $response->expects($this->once())
            ->method('representJson')
            ->with($this->callback(function (string $json) use (&$captured): bool {
                $captured = json_decode($json, true);
                return true;
            }));

        $result = $this->buildResult(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $this->createMock(LoggerInterface::class)
        );
        $result->renderResult($response);

        $this->assertCount(2, $captured['events']);
        $this->assertSame('text_delta', $captured['events'][0]['type']);
        $this->assertSame('turn_complete', $captured['events'][1]['type']);
    }

    public function testAFailureWhileWritingTheBodyStillReleasesTheSlot(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->generatorOf([Event::textDelta('hi')]));

        $response = $this->createMock(HttpResponse::class);
        $response->method('representJson')->willThrowException(new \RuntimeException('write failed'));

        $result = $this->buildResult(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $this->createMock(LoggerInterface::class)
        );

        $this->expectException(\RuntimeException::class);
        $result->renderResult($response);
    }

    public function testBusyEventProducesTheErrorAndACompletionEventWithoutCallingTheOrchestrator(): void
    {
        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->expects($this->never())->method('streamTurn');

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $response = $this->createMock(HttpResponse::class);
        $captured = null;
        $response->method('representJson')->with($this->callback(function (string $json) use (&$captured): bool {
            $captured = json_decode($json, true);
            return true;
        }));

        $result = $this->buildResult(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            Event::error('The assistant is busy right now. Please try again in a few seconds.', 5),
            $orchestrator,
            $this->createMock(LoggerInterface::class)
        );
        $result->renderResult($response);

        $this->assertCount(2, $captured['events']);
        $this->assertSame('error', $captured['events'][0]['type']);
        $this->assertSame('turn_complete', $captured['events'][1]['type']);
    }

    public function testThrowingGeneratorProducesAnErrorEventAndStillReleasesTheSlotAndLogs(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->throwingGenerator());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $response = $this->createMock(HttpResponse::class);
        $captured = null;
        $response->method('representJson')->with($this->callback(function (string $json) use (&$captured): bool {
            $captured = json_decode($json, true);
            return true;
        }));

        $result = $this->buildResult(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $logger
        );
        $result->renderResult($response);

        $this->assertCount(2, $captured['events']);
        $this->assertSame('text_delta', $captured['events'][0]['type']);
        $this->assertSame('error', $captured['events'][1]['type']);
    }

    public function testErrorLogMasksAnApiKeyAndIncludesTheExceptionClass(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->throwingGeneratorWithApiKeyInMessage());

        $logger = $this->createMock(LoggerInterface::class);
        $captured = null;
        $logger->expects($this->once())->method('error')->with(
            $this->callback(function (string $message) use (&$captured): bool {
                $captured = $message;
                return true;
            })
        );

        $response = $this->createMock(HttpResponse::class);
        $response->method('representJson');

        $result = $this->buildResult(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $logger
        );
        $result->renderResult($response);

        $this->assertStringContainsString('RuntimeException', $captured);
        $this->assertStringContainsString('sk-ant-***', $captured);
        $this->assertStringNotContainsString('sk-ant-abc123XYZ', $captured);
    }
}
