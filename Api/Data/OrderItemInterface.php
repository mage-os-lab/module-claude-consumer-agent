<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface OrderItemInterface
{
    public function getProductId(): string;

    public function getTitle(): string;

    public function getQuantity(): int;

    public function getPrice(): float;

    public function getOptionValues(): array;

    public function getVariantOf(): ?string;

    public function toArray(): array;
}
