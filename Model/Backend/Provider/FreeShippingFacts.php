<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Backend\FulfillmentProviderInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\FulfillmentOption;

final class FreeShippingFacts implements FulfillmentProviderInterface
{
    private const PATH_ACTIVE = 'carriers/freeshipping/active';
    private const PATH_SUBTOTAL = 'carriers/freeshipping/free_shipping_subtotal';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function options(SessionContext $ctx, array $productIds): array
    {
        if (!$this->scopeConfig->isSetFlag(self::PATH_ACTIVE, ScopeInterface::SCOPE_STORE, $ctx->storeId)) {
            return [];
        }

        $threshold = (float)$this->scopeConfig->getValue(
            self::PATH_SUBTOTAL,
            ScopeInterface::SCOPE_STORE,
            $ctx->storeId
        );

        return [
            new FulfillmentOption(
                FulfillmentOptionInterface::METHOD_SHIPPING,
                'free shipping on orders over ' . $this->formatThreshold($threshold),
                0.0
            ),
        ];
    }

    private function formatThreshold(float $threshold): string
    {
        return (float)(int)$threshold === $threshold ? (string)(int)$threshold : (string)$threshold;
    }
}
