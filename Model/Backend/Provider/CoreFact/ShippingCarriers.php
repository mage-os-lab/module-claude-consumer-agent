<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class ShippingCarriers implements CoreFactProviderInterface
{
    public function __construct(
        private readonly \Magento\Shipping\Model\Config $shippingConfig,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function line(int $storeId): ?string
    {
        $titles = [];
        foreach (array_keys($this->shippingConfig->getActiveCarriers($storeId)) as $code) {
            $title = trim((string)$this->scopeConfig->getValue(
                'carriers/' . $code . '/title',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
            if ($title !== '') {
                $titles[] = $title;
            }
        }
        if ($titles === []) {
            return null;
        }
        return 'Shipping carriers: ' . implode(', ', $titles) . '.';
    }
}
