<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class GuestCheckout implements CoreFactProviderInterface
{
    private const PATH_GUEST_CHECKOUT = 'checkout/options/guest_checkout';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function line(int $storeId): ?string
    {
        $allowed = $this->scopeConfig->isSetFlag(self::PATH_GUEST_CHECKOUT, ScopeInterface::SCOPE_STORE, $storeId);
        return $allowed ? 'Guest checkout: allowed.' : 'Guest checkout: an account is required.';
    }
}
