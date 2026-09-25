<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use DateTimeImmutable;
use DateTimeInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderItemInterface;

final class Order implements OrderInterface
{
    public function __construct(
        private readonly string $orderId,
        private readonly string $status,
        private readonly \DateTimeImmutable $placedAt,
        private readonly float $total,
        private readonly string $currency = 'USD',
        private readonly array $items = [],
        private readonly ?string $estimatedDelivery = null,
        private readonly ?string $trackingUrl = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
            $items[] = is_array($item) ? OrderItem::fromArray($item) : $item;
        }

        $status = (string)($data['status'] ?? self::STATUS_UNKNOWN);
        if (!in_array($status, self::STATUSES, true)) {
            $status = self::STATUS_UNKNOWN;
        }

        $placedAt = $data['placed_at'] ?? null;
        if (!$placedAt instanceof DateTimeImmutable) {
            $placedAt = $placedAt ? new DateTimeImmutable((string)$placedAt) : new DateTimeImmutable('now');
        }

        return new self(
            orderId: (string)($data['order_id'] ?? ''),
            status: $status,
            placedAt: $placedAt,
            total: (float)($data['total'] ?? 0.0),
            currency: (string)($data['currency'] ?? 'USD'),
            items: $items,
            estimatedDelivery: isset($data['estimated_delivery']) ? (string)$data['estimated_delivery'] : null,
            trackingUrl: isset($data['tracking_url']) ? (string)$data['tracking_url'] : null
        );
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPlacedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getEstimatedDelivery(): ?string
    {
        return $this->estimatedDelivery;
    }

    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'status' => $this->status,
            'placed_at' => $this->placedAt->format(DateTimeInterface::ATOM),
            'items' => array_map(
                static fn (OrderItemInterface $item): array => $item->toArray(),
                $this->items
            ),
            'total' => $this->total,
            'currency' => $this->currency,
            'estimated_delivery' => $this->estimatedDelivery,
            'tracking_url' => $this->trackingUrl,
        ];
    }
}
