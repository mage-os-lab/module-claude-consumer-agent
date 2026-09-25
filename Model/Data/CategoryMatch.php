<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface;

final class CategoryMatch implements CategoryMatchInterface
{
    public function __construct(
        private readonly int $categoryId,
        private readonly string $name,
        private readonly array $path = [],
        private readonly int $productCount = 0,
        private readonly int $level = 0
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            categoryId: (int)($data['category_id'] ?? 0),
            name: (string)($data['name'] ?? ''),
            path: is_array($data['path'] ?? null) ? array_values(array_map('strval', $data['path'])) : [],
            productCount: (int)($data['product_count'] ?? 0),
            level: (int)($data['level'] ?? 0)
        );
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): array
    {
        return $this->path;
    }

    public function getProductCount(): int
    {
        return $this->productCount;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'path' => implode(' > ', $this->path),
            'product_count' => $this->productCount,
        ];
    }
}
