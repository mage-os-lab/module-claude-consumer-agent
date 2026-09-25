<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

/**
 * Pools every MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface bound in
 * di.xml. Keys carry the desired output order, the same convention Backend\Provider\
 * FulfillmentAggregator uses for its own provider pool.
 */
final class CoreFacts
{
    public function __construct(
        private readonly array $providers
    ) {
    }

    public function lines(int $storeId): array
    {
        $providers = $this->providers;
        ksort($providers);

        $lines = [];
        foreach ($providers as $provider) {
            $line = $provider->line($storeId);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
