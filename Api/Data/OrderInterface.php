<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

use DateTimeImmutable;

interface OrderInterface
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_RETURN_INITIATED = 'return_initiated';
    public const STATUS_DELAYED = 'delayed';
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
        self::STATUS_RETURN_INITIATED,
        self::STATUS_DELAYED,
        self::STATUS_UNKNOWN,
    ];

    public function getOrderId(): string;

    public function getStatus(): string;

    public function getPlacedAt(): DateTimeImmutable;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\OrderItemInterface[]
     */
    public function getItems(): array;

    public function getTotal(): float;

    public function getCurrency(): string;

    public function getEstimatedDelivery(): ?string;

    public function getTrackingUrl(): ?string;

    public function toArray(): array;
}
