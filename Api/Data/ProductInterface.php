<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface ProductInterface
{
    public function getProductId(): string;

    public function getTitle(): string;

    public function getBrand(): ?string;

    public function getPrice(): float;

    public function getOriginalPrice(): ?float;

    public function getCurrency(): string;

    public function getRating(): ?float;

    public function getReviewCount(): ?int;

    public function getImageUrl(): ?string;

    public function getUrl(): ?string;

    public function getCategory(): ?string;

    public function getLabels(): array;

    public function getAttributes(): array;

    public function isInStock(): bool;

    public function getShortDescription(): ?string;

    public function getOptions(): array;

    public function getOptionValues(): array;

    public function getVariantOf(): ?string;

    public function hasRequiredCustomOptions(): bool;

    public function getCustomOptions(): array;

    public function toArray(): array;
}
