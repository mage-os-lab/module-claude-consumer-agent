<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Sales\Model\Order;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\OrderStatusMapper;
use PHPUnit\Framework\TestCase;

final class OrderStatusMapperTest extends TestCase
{
    private function mapper(): OrderStatusMapper
    {
        return new OrderStatusMapper();
    }

    private function order(string $state, string $status): Order&\PHPUnit\Framework\MockObject\MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($status);
        return $order;
    }

    public function testCancelledState(): void
    {
        $order = $this->order(Order::STATE_CANCELED, 'canceled');

        $this->assertSame(OrderInterface::STATUS_CANCELLED, $this->mapper()->map($order, false));
    }

    public function testClosedStateMapsToRefunded(): void
    {
        $order = $this->order(Order::STATE_CLOSED, 'closed');

        $this->assertSame(OrderInterface::STATUS_REFUNDED, $this->mapper()->map($order, false));
    }

    public function testStatusContainingReturnMapsToReturnInitiated(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'return_requested');

        $this->assertSame(OrderInterface::STATUS_RETURN_INITIATED, $this->mapper()->map($order, false));
    }

    public function testCompleteStateMapsToDelivered(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, 'complete');

        $this->assertSame(OrderInterface::STATUS_DELIVERED, $this->mapper()->map($order, false));
    }

    public function testHasTrackingMapsToShipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');

        $this->assertSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order, true));
    }

    public function testShippedStatusMapsToShippedEvenWithoutTracking(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'shipped');

        $this->assertSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order, false));
    }

    public function testNoTrackingAndProcessingStatusDoesNotMapToShipped(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');

        $this->assertNotSame(OrderInterface::STATUS_SHIPPED, $this->mapper()->map($order, false));
    }

    public function testHoldedStateMapsToDelayed(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');

        $this->assertSame(OrderInterface::STATUS_DELAYED, $this->mapper()->map($order, false));
    }

    public function testDefaultsToProcessing(): void
    {
        $order = $this->order(Order::STATE_NEW, 'pending');

        $this->assertSame(OrderInterface::STATUS_PROCESSING, $this->mapper()->map($order, false));
    }
}
