<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Data\PolicyInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductDetailsInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductInterface;
use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;
use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface StorefrontBackendInterface
{
    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\ProductInterface[]
     */
    public function searchProducts(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        int $limit
    ): array;

    public function getProductDetails(SessionContext $ctx, string $productId): ?ProductDetailsInterface;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface[]
     */
    public function searchCategories(SessionContext $ctx, string $keywords, int $limit): array;

    public function getCart(SessionContext $ctx): CartInterface;

    public function addToCart(SessionContext $ctx, string $productId, int $quantity, array $options = []): CartInterface;

    public function updateCartItem(SessionContext $ctx, string $productId, int $quantity): CartInterface;

    public function removeFromCart(SessionContext $ctx, string $productId): CartInterface;

    public function getPreferences(SessionContext $ctx): UserPreferencesInterface;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\OrderInterface[]
     */
    public function getOrders(SessionContext $ctx, int $limit): array;

    public function getOrder(SessionContext $ctx, string $orderId): ?OrderInterface;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\PolicyInterface[]
     */
    public function searchPolicies(SessionContext $ctx, string $query): array;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface[]
     */
    public function getFulfillmentOptions(SessionContext $ctx, array $productIds): array;
}
