<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrders;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use MageOS\AiShoppingAssistant\Model\Data\OrderItem;
use PHPUnit\Framework\TestCase;

final class GetOrdersTest extends TestCase
{
    private const SERIALIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Serializer::class;
    private const FENCE_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence::class;
    private const SANITIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer::class;

    protected function setUp(): void
    {
        foreach ([self::SERIALIZER_CLASS, self::FENCE_CLASS, self::SANITIZER_CLASS] as $class) {
            if (!class_exists($class)) {
                $this->markTestSkipped($class . ' is not present on disk yet (task T3 has not landed).');
            }
        }
    }

    private function buildSerializer(): object
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        $serializerClass = self::SERIALIZER_CLASS;
        $fence = new $fenceClass(new $sanitizerClass());
        return new $serializerClass($fence);
    }

    private function buildFence(): object
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        return new $fenceClass(new $sanitizerClass());
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    private function buildOrder(): Order
    {
        return Order::fromArray([
            'order_id' => 'o-1',
            'status' => 'shipped',
            'placed_at' => '2026-01-01T00:00:00+00:00',
            'total' => 158.0,
            'currency' => 'USD',
            'items' => [
                ['product_id' => 'p-100', 'title' => 'Tent', 'quantity' => 1, 'price' => 149.0],
                ['product_id' => 'p-200', 'title' => 'Mug', 'quantity' => 1, 'price' => 9.0],
            ],
        ]);
    }

    public function testOrdersAreFencedAndOrderItemsBecomeProducts(): void
    {
        $order = $this->buildOrder();
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())->method('getOrders')->with($this->anything(), 5)->willReturn([$order]);

        $handler = new GetOrders($backend, $this->buildSerializer(), $this->buildFence());
        $outcome = $handler->handle([], $this->context(), new SessionState(), new AgentConfig());

        $this->assertFalse($outcome->isError);
        $this->assertStringStartsWith("Order history:\n", $outcome->resultText);
        $this->assertCount(2, $outcome->products);
        $this->assertSame(
            [
                'product_id' => 'p-100',
                'title' => 'Tent',
                'price' => 149.0,
                'currency' => 'USD',
                'option_values' => [],
                'variant_of' => null,
                'in_stock' => true,
            ],
            $outcome->products[0]
        );
    }

    public function testLimitIsCappedAtTwenty(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())->method('getOrders')->with($this->anything(), 20)->willReturn([]);

        $handler = new GetOrders($backend, $this->buildSerializer(), $this->buildFence());
        $handler->handle(['limit' => 999], $this->context(), new SessionState(), new AgentConfig());
    }

    public function testLimitIsFlooredAtOneWhenRequestedLimitIsZeroOrLess(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())->method('getOrders')->with($this->anything(), 1)->willReturn([]);

        $handler = new GetOrders($backend, $this->buildSerializer(), $this->buildFence());
        $handler->handle(['limit' => -3], $this->context(), new SessionState(), new AgentConfig());
    }
}
