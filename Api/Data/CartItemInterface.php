<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface CartItemInterface
{
    public function getProductId(): string;

    public function getTitle(): string;

    public function getPrice(): float;

    public function getQuantity(): int;

    public function getImageUrl(): ?string;

    public function getOptionValues(): array;

    public function getVariantOf(): ?string;

    public function getLineTotal(): float;

    public function getItemId(): ?int;

    public function toArray(): array;
}
