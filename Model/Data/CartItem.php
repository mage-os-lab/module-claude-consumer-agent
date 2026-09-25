<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\CartItemInterface;

final class CartItem implements CartItemInterface
{
    public function __construct(
        private readonly string $productId,
        private readonly string $title,
        private readonly float $price,
        private readonly int $quantity,
        private readonly ?string $imageUrl = null,
        private readonly array $optionValues = [],
        private readonly ?string $variantOf = null,
        private readonly ?int $itemId = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            productId: (string)($data['product_id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            price: (float)($data['price'] ?? 0.0),
            quantity: (int)($data['quantity'] ?? 1),
            imageUrl: isset($data['image_url']) ? (string)$data['image_url'] : null,
            optionValues: is_array($data['option_values'] ?? null) ? $data['option_values'] : [],
            variantOf: isset($data['variant_of']) ? (string)$data['variant_of'] : null,
            itemId: isset($data['item_id']) ? (int)$data['item_id'] : null
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

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getOptionValues(): array
    {
        return $this->optionValues;
    }

    public function getVariantOf(): ?string
    {
        return $this->variantOf;
    }

    public function getLineTotal(): float
    {
        return round($this->price * $this->quantity, 2);
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'image_url' => $this->imageUrl,
            'option_values' => $this->optionValues,
            'variant_of' => $this->variantOf,
            'line_total' => $this->getLineTotal(),
            'item_id' => $this->itemId,
        ];
    }
}
