<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;

final class DynamicContext
{
    private const MAX_CHARS = 6000;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function build(
        ?UserPreferencesInterface $prefs,
        ?CartInterface $cart,
        PageContextInterface $page,
        string $hourIso
    ): string {
        $payload = [];
        if ($prefs !== null) {
            $payload['customer'] = [
                'name' => $prefs->getDisplayName(),
                'loyalty_tier' => $prefs->getLoyaltyTier(),
                'location' => $prefs->getDefaultLocation(),
                'preferences' => $prefs->getPreferences(),
            ];
        }
        $payload['saved_memory'] = 'none';
        if ($cart !== null) {
            $payload['cart'] = [
                'item_count' => $cart->getItemCount(),
                'subtotal' => $cart->getSubtotal(),
                'items' => $this->cartItems($cart),
            ];
        }
        $payload['current_page'] = $page->toArray();
        $payload['local_time'] = $hourIso;

        return "# Session context\n\n" . $this->fence->fencePayload($payload, self::MAX_CHARS);
    }

    private function cartItems(CartInterface $cart): array
    {
        $items = [];
        foreach ($cart->getItems() as $item) {
            $row = [
                'product_id' => $item->getProductId(),
                'title' => $item->getTitle(),
                'quantity' => $item->getQuantity(),
            ];
            if ($item->getOptionValues() !== []) {
                $row['option_values'] = $item->getOptionValues();
            }
            $items[] = $row;
        }
        return $items;
    }
}
