<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class MinimumOrder implements CoreFactProviderInterface
{
    private const PATH_ACTIVE = 'sales/minimum_order/active';
    private const PATH_AMOUNT = 'sales/minimum_order/amount';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager
    ) {
    }

    public function line(int $storeId): ?string
    {
        if (!$this->scopeConfig->isSetFlag(self::PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return null;
        }
        $amount = (float)$this->scopeConfig->getValue(self::PATH_AMOUNT, ScopeInterface::SCOPE_STORE, $storeId);
        try {
            $currency = $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        } catch (NoSuchEntityException $exception) {
            return null;
        }
        return 'Minimum order: ' . $this->formatAmount($amount) . ' ' . $currency . '.';
    }

    private function formatAmount(float $amount): string
    {
        return (float)(int)$amount === $amount ? (string)(int)$amount : (string)$amount;
    }
}
