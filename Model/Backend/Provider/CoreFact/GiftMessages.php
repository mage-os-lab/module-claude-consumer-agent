<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class GiftMessages implements CoreFactProviderInterface
{
    private const PATH_ALLOW_ORDER = 'sales/gift_options/allow_order';
    private const PATH_ALLOW_ITEMS = 'sales/gift_options/allow_items';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function line(int $storeId): ?string
    {
        $allowed = $this->scopeConfig->isSetFlag(self::PATH_ALLOW_ORDER, ScopeInterface::SCOPE_STORE, $storeId)
            || $this->scopeConfig->isSetFlag(self::PATH_ALLOW_ITEMS, ScopeInterface::SCOPE_STORE, $storeId);
        return $allowed ? 'Gift messages: available at checkout.' : 'Gift messages: not offered.';
    }
}
