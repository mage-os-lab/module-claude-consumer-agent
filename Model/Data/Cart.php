<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\CartItemInterface;

final class Cart implements CartInterface
{
    public function __construct(
        private readonly array $items = [],
        private readonly string $currency = 'USD'
    ) {
    }

    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
            $items[] = is_array($item) ? CartItem::fromArray($item) : $item;
        }

        return new self($items, (string)($data['currency'] ?? 'USD'));
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getItemCount(): int
    {
        return array_sum(array_map(
            static fn (CartItemInterface $item): int => $item->getQuantity(),
            $this->items
        ));
    }

    public function getSubtotal(): float
    {
        return round(array_sum(array_map(
            static fn (CartItemInterface $item): float => $item->getLineTotal(),
            $this->items
        )), 2);
    }

    public function find(string $productId): ?CartItemInterface
    {
        foreach ($this->items as $item) {
            if ($item->getProductId() === $productId) {
                return $item;
            }
        }

        return null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (CartItemInterface $item): array => $item->toArray(),
                $this->items
            ),
            'currency' => $this->currency,
            'item_count' => $this->getItemCount(),
            'subtotal' => $this->getSubtotal(),
        ];
    }
}
