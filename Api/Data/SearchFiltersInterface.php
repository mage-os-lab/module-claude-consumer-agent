<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface SearchFiltersInterface
{
    public function getCategory(): ?string;

    public function getCategoryId(): ?int;

    public function getMinPrice(): ?float;

    public function getMaxPrice(): ?float;

    public function getMinRating(): ?float;

    public function getAttributes(): array;

    public function getSort(): string;

    public function toArray(): array;
}
