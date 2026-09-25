<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\OrderItemInterface;

final class OrderItem implements OrderItemInterface
{
    public function __construct(
        private readonly string $productId,
        private readonly string $title,
        private readonly int $quantity,
        private readonly float $price,
        private readonly array $optionValues = [],
        private readonly ?string $variantOf = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            productId: (string)($data['product_id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            quantity: (int)($data['quantity'] ?? 1),
            price: (float)($data['price'] ?? 0.0),
            optionValues: is_array($data['option_values'] ?? null) ? $data['option_values'] : [],
            variantOf: isset($data['variant_of']) ? (string)$data['variant_of'] : null
        );
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getOptionValues(): array
    {
        return $this->optionValues;
    }

    public function getVariantOf(): ?string
    {
        return $this->variantOf;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'option_values' => $this->optionValues,
            'variant_of' => $this->variantOf,
        ];
    }
}
