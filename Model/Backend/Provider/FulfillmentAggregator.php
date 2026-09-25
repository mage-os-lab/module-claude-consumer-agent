<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use MageOS\AiShoppingAssistant\Api\Backend\FulfillmentProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

final class FulfillmentAggregator implements FulfillmentProviderInterface
{
    public function __construct(
        private readonly array $providers
    ) {
    }

    public function options(SessionContext $ctx, array $productIds): array
    {
        $providers = $this->providers;
        ksort($providers);

        $result = [];
        foreach ($providers as $provider) {
            foreach ($provider->options($ctx, $productIds) as $option) {
                $result[] = $option;
            }
        }

        return $result;
    }
}
