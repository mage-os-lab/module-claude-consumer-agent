<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Data\PolicyInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductDetailsInterface;
use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;
use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CategoryMatch;
use MageOS\AiShoppingAssistant\Model\Data\FulfillmentOption;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use MageOS\AiShoppingAssistant\Model\Data\Policy;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use MageOS\AiShoppingAssistant\Model\Data\UserPreferences;

/**
 * Backs the eval runner's fixture mode: a product catalog, order history and policy set
 * seeded from a case's state, with cart writes mutating an in-memory line list. No tool
 * call ever leaves this object, so a fixture run never touches the real storefront.
 */
final class FakeBackend implements StorefrontBackendInterface
{
    private array $cart = [];

    public function __construct(
        private readonly array $catalog = [],
        private readonly array $preferences = [],
        private readonly array $orders = [],
        private readonly array $policies = [],
        private readonly array $fulfillmentOptions = [],
        private readonly array $categories = []
    ) {
    }

    public function searchProducts(SessionContext $ctx, string $query, ?SearchFiltersInterface $filters, int $limit): array
    {
        $categoryId = $filters !== null ? $filters->getCategoryId() : null;
        $needle = mb_strtolower(trim($query));
        $records = [];
        foreach ($this->catalog as $record) {
            if ($needle === '' && $categoryId !== null) {
                if ($this->recordInCategory($record, $categoryId)) {
                    $records[] = $record;
                }
                continue;
            }
            if ($this->matchesQuery($record, $needle)) {
                $records[] = $record;
            }
        }
        if ($filters !== null && $filters->getSort() === 'best_sellers') {
            $records = $this->sortByQtyOrdered($records);
        }
        $matches = array_map(static fn (array $record): Product => Product::fromArray($record), $records);
        return array_slice($matches, 0, max(1, $limit));
    }

    public function searchCategories(SessionContext $ctx, string $keywords, int $limit): array
    {
        $needle = mb_strtolower(trim($keywords));
        $matches = [];
        foreach ($this->categories as $record) {
            if ($this->matchesQuery($record, $needle, ['name'])) {
                $matches[] = CategoryMatch::fromArray($record);
            }
        }
        return array_slice($matches, 0, max(1, $limit));
    }

    public function getProductDetails(SessionContext $ctx, string $productId): ?ProductDetailsInterface
    {
        $record = $this->catalog[$productId] ?? null;
        if ($record === null) {
            return null;
        }
        $variants = [];
        foreach ($this->catalog as $candidate) {
            if ((string)($candidate['variant_of'] ?? '') === $productId) {
                $variants[] = Product::fromArray($candidate);
            }
        }
        return ProductDetails::fromProduct(
            Product::fromArray($record),
            isset($record['long_description']) ? (string)$record['long_description'] : null,
            is_array($record['specs'] ?? null) ? $record['specs'] : [],
            $variants
        );
    }

    public function getCart(SessionContext $ctx): CartInterface
    {
        return $this->buildCart();
    }

    public function addToCart(SessionContext $ctx, string $productId, int $quantity, array $options = []): CartInterface
    {
        $record = $this->catalog[$productId] ?? null;
        if ($record === null || !(bool)($record['in_stock'] ?? true)) {
            throw new Unavailable('product_id ' . $productId . ' is not available.');
        }
        $line = $this->cart[$productId] ?? $this->newLine($productId, $record);
        $line['quantity'] = (int)$line['quantity'] + $quantity;
        if ($options !== []) {
            $existing = is_array($line['option_values'] ?? null) ? $line['option_values'] : [];
            $line['option_values'] = $options + $existing;
        }
        $this->cart[$productId] = $line;
        return $this->buildCart();
    }

    public function updateCartItem(SessionContext $ctx, string $productId, int $quantity): CartInterface
    {
        $record = $this->catalog[$productId] ?? [];
        $line = $this->cart[$productId] ?? $this->newLine($productId, $record);
        $line['quantity'] = $quantity;
        $this->cart[$productId] = $line;
        return $this->buildCart();
    }

    public function removeFromCart(SessionContext $ctx, string $productId): CartInterface
    {
        unset($this->cart[$productId]);
        return $this->buildCart();
    }

    public function getPreferences(SessionContext $ctx): UserPreferencesInterface
    {
        $preferences = $this->preferences !== [] ? $this->preferences : ['user_id' => (string)($ctx->customerId ?? 'guest')];
        return UserPreferences::fromArray($preferences);
    }

    public function getOrders(SessionContext $ctx, int $limit): array
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired('Sign in to see orders.');
        }
        $orders = array_slice($this->orders, 0, max(1, $limit));
        return array_map(static fn (array $order): OrderInterface => Order::fromArray($order), $orders);
    }

    public function getOrder(SessionContext $ctx, string $orderId): ?OrderInterface
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired('Sign in to see orders.');
        }
        foreach ($this->orders as $order) {
            if ((string)($order['order_id'] ?? '') === $orderId) {
                return Order::fromArray($order);
            }
        }
        return null;
    }

    public function searchPolicies(SessionContext $ctx, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        $matches = [];
        foreach ($this->policies as $policy) {
            if ($this->matchesQuery($policy, $needle, ['title', 'content'])) {
                $matches[] = Policy::fromArray($policy);
            }
        }
        return $matches;
    }

    public function getFulfillmentOptions(SessionContext $ctx, array $productIds): array
    {
        return array_map(
            static fn (array $option): FulfillmentOptionInterface => FulfillmentOption::fromArray($option),
            $this->fulfillmentOptions
        );
    }

    private function newLine(string $productId, array $record): array
    {
        return [
            'product_id' => $productId,
            'title' => (string)($record['title'] ?? $productId),
            'price' => (float)($record['price'] ?? 0.0),
            'quantity' => 0,
            'image_url' => $record['image_url'] ?? null,
            'option_values' => is_array($record['option_values'] ?? null) ? $record['option_values'] : [],
            'variant_of' => $record['variant_of'] ?? null,
        ];
    }

    private function sortByQtyOrdered(array $records): array
    {
        $indexed = array_values($records);
        usort($indexed, static function (array $a, array $b): int {
            $qtyA = $a['qty_ordered'] ?? null;
            $qtyB = $b['qty_ordered'] ?? null;
            if ($qtyA === null && $qtyB === null) {
                return 0;
            }
            if ($qtyA === null) {
                return 1;
            }
            if ($qtyB === null) {
                return -1;
            }
            return $qtyB <=> $qtyA;
        });
        return $indexed;
    }

    private function recordInCategory(array $record, int $categoryId): bool
    {
        $categoryIds = is_array($record['category_ids'] ?? null) ? $record['category_ids'] : [];
        return in_array($categoryId, array_map('intval', $categoryIds), true);
    }

    private function matchesQuery(array $record, string $needle, array $fields = ['title', 'short_description']): bool
    {
        if ($needle === '') {
            return true;
        }
        $haystack = '';
        foreach ($fields as $field) {
            $haystack .= ' ' . mb_strtolower((string)($record[$field] ?? ''));
        }
        $tokens = preg_split('/\s+/', $needle, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens !== false ? $tokens : [] as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }
        return false;
    }

    private function buildCart(): CartInterface
    {
        return Cart::fromArray(['items' => array_values($this->cart), 'currency' => 'USD']);
    }
}
