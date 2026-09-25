<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use Magento\Sales\Model\Order;

interface OrderStatusMapperInterface
{
    public function map(Order $order, bool $hasTracking): string;
}
