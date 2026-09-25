<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;

final class PageContext implements PageContextInterface
{
    private const QUERY_MAX_LENGTH = 200;

    private const NAME_MAX_LENGTH = 120;

    public function __construct(
        private readonly string $pageType = self::PAGE_TYPE_HOME,
        private readonly ?string $productId = null,
        private readonly ?string $query = null,
        private readonly ?string $categoryId = null,
        private readonly ?string $categoryName = null,
        private readonly ?string $productName = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        $pageType = (string)($data['page_type'] ?? self::PAGE_TYPE_HOME);
        if (!in_array($pageType, self::PAGE_TYPES, true)) {
            $pageType = self::PAGE_TYPE_OTHER;
        }

        return new self(
            pageType: $pageType,
            productId: isset($data['product_id']) ? (string)$data['product_id'] : null,
            query: self::readText($data['query'] ?? null, self::QUERY_MAX_LENGTH),
            categoryId: self::readCategoryId($data['category_id'] ?? null),
            categoryName: self::readText($data['category_name'] ?? null, self::NAME_MAX_LENGTH),
            productName: self::readText($data['product_name'] ?? null, self::NAME_MAX_LENGTH)
        );
    }

    public function getPageType(): string
    {
        return $this->pageType;
    }

    public function getProductId(): ?string
    {
        return $this->productId;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function getQuery(): ?string
    {
        return $this->query;
    }

    public function getCategoryId(): ?string
    {
        return $this->categoryId;
    }

    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }

    public function toArray(): array
    {
        return [
            'page_type' => $this->pageType,
            'product_id' => $this->productId,
            'product_name' => $this->productName,
            'query' => $this->query,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
        ];
    }

    private static function readCategoryId(mixed $value): ?string
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        return (string)$value;
    }

    private static function readText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return mb_substr($trimmed, 0, $maxLength);
    }
}
