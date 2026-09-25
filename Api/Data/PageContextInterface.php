<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface PageContextInterface
{
    public const PAGE_TYPE_HOME = 'home';
    public const PAGE_TYPE_SEARCH = 'search';
    public const PAGE_TYPE_PRODUCT = 'product';
    public const PAGE_TYPE_CATEGORY = 'category';
    public const PAGE_TYPE_CART = 'cart';
    public const PAGE_TYPE_ORDERS = 'orders';
    public const PAGE_TYPE_OTHER = 'other';

    public const PAGE_TYPES = [
        self::PAGE_TYPE_HOME,
        self::PAGE_TYPE_SEARCH,
        self::PAGE_TYPE_PRODUCT,
        self::PAGE_TYPE_CATEGORY,
        self::PAGE_TYPE_CART,
        self::PAGE_TYPE_ORDERS,
        self::PAGE_TYPE_OTHER,
    ];

    public function getPageType(): string;

    public function getProductId(): ?string;

    public function getProductName(): ?string;

    public function getQuery(): ?string;

    public function getCategoryId(): ?string;

    public function getCategoryName(): ?string;

    public function toArray(): array;
}
