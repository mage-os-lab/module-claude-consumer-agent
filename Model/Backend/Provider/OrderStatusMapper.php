<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Sales\Model\Order;
use MageOS\AiShoppingAssistant\Api\Backend\OrderStatusMapperInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;

final class OrderStatusMapper implements OrderStatusMapperInterface
{
    public function map(Order $order, bool $hasTracking): string
    {
        $state = (string)$order->getState();
        $status = (string)$order->getStatus();

        if ($state === Order::STATE_CANCELED) {
            return OrderInterface::STATUS_CANCELLED;
        }
        if ($state === Order::STATE_CLOSED) {
            return OrderInterface::STATUS_REFUNDED;
        }
        if (stripos($status, 'return') !== false) {
            return OrderInterface::STATUS_RETURN_INITIATED;
        }
        if ($state === Order::STATE_COMPLETE) {
            return OrderInterface::STATUS_DELIVERED;
        }
        if ($hasTracking || $status === 'shipped') {
            return OrderInterface::STATUS_SHIPPED;
        }
        if ($state === Order::STATE_HOLDED) {
            return OrderInterface::STATUS_DELAYED;
        }

        return OrderInterface::STATUS_PROCESSING;
    }
}
