<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;

final class SearchFilters implements SearchFiltersInterface
{
    public function __construct(
        private readonly ?string $category = null,
        private readonly ?int $categoryId = null,
        private readonly ?float $minPrice = null,
        private readonly ?float $maxPrice = null,
        private readonly ?float $minRating = null,
        private readonly array $attributes = [],
        private readonly string $sort = 'relevance'
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            category: isset($data['category']) ? (string)$data['category'] : null,
            categoryId: isset($data['category_id']) ? (int)$data['category_id'] : null,
            minPrice: isset($data['min_price']) ? (float)$data['min_price'] : null,
            maxPrice: isset($data['max_price']) ? (float)$data['max_price'] : null,
            minRating: isset($data['min_rating']) ? (float)$data['min_rating'] : null,
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            sort: (string)($data['sort'] ?? 'relevance')
        );
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function getMinPrice(): ?float
    {
        return $this->minPrice;
    }

    public function getMaxPrice(): ?float
    {
        return $this->maxPrice;
    }

    public function getMinRating(): ?float
    {
        return $this->minRating;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getSort(): string
    {
        return $this->sort;
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'category_id' => $this->categoryId,
            'min_price' => $this->minPrice,
            'max_price' => $this->maxPrice,
            'min_rating' => $this->minRating,
            'attributes' => $this->attributes,
            'sort' => $this->sort,
        ];
    }
}
