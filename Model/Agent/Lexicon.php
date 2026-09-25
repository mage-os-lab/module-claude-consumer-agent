<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

final class Lexicon
{
    private const POLICY_CUES = [
        '?',
        'how',
        'what',
        'when',
        'can i',
        'could i',
        'do you',
        'does',
        'is there',
        'tell me',
        'explain',
        'how long',
        'how much',
    ];

    private const ORDER_CUES = [
        '?',
        'where',
        'when',
        'status',
        'cancel',
        'change',
        'return',
        'refund',
        'late',
        'arrive',
        'arrived',
        'track',
        'missing',
        'damaged',
        "hasn't",
        'delayed',
    ];

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly array $additionalPolicyTerms = [],
        private readonly array $additionalOrderTerms = []
    ) {
    }

    public function policyTerms(int $storeId): array
    {
        return $this->merge($this->storeConfig->agent($storeId)->policyIntentTerms, $this->additionalPolicyTerms);
    }

    public function orderTerms(int $storeId): array
    {
        return $this->merge($this->storeConfig->agent($storeId)->orderIntentTerms, $this->additionalOrderTerms);
    }

    public function policyCues(): array
    {
        return self::POLICY_CUES;
    }

    public function orderCues(): array
    {
        return self::ORDER_CUES;
    }

    private function merge(array $configLines, array $additional): array
    {
        $combined = array_map(
            static fn (string $line): string => trim($line),
            array_merge($configLines, $additional)
        );
        $filtered = array_filter($combined, static fn (string $line): bool => $line !== '');
        $unique = array_values(array_unique($filtered));
        sort($unique);
        return $unique;
    }
}
