<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Grounding;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;

final class Rules
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Lexicon $lexicon,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates $skuCandidates,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface $skuMatcher
    ) {
    }

    public function firstForcedTool(
        AgentConfig $config,
        string $text,
        SessionState $state,
        PageContextInterface $page,
        bool $isFirstTurn,
        int $storeId
    ): ?string {
        if ($this->matchesStoreFact($text, $config->storeFacts)) {
            return null;
        }
        if ($config->enablePolicies
            && $this->matches($text, $this->lexicon->policyTerms($storeId), $this->lexicon->policyCues())
        ) {
            return 'search_policies';
        }
        if ($config->enableOrders
            && $this->matches($text, $this->lexicon->orderTerms($storeId), $this->lexicon->orderCues())
        ) {
            return 'get_orders';
        }
        $candidates = $this->skuCandidates->fromText($text);
        $sku = $this->skuMatcher->firstExisting($candidates, $storeId);
        if ($sku !== null && !$state->hasSeenCaseInsensitive($sku)) {
            return 'get_product_details';
        }
        $pageProductId = $page->getProductId();
        if ($isFirstTurn
            && $page->getPageType() === PageContextInterface::PAGE_TYPE_PRODUCT
            && $pageProductId !== null
            && !$state->hasSeen($pageProductId)
        ) {
            return 'get_product_details';
        }
        return null;
    }

    private function matchesStoreFact(string $text, array $facts): bool
    {
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $source = (string)($fact['source'] ?? 'text');
            if ($source !== 'text' && $source !== 'not_offered') {
                continue;
            }
            $keywords = is_array($fact['keywords'] ?? null) ? $fact['keywords'] : [];
            if ($keywords === []) {
                $keywords = [trim((string)($fact['topic'] ?? ''))];
            }
            if ($this->matchesAny($text, $keywords)) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $text, array $terms, array $cues): bool
    {
        if ($text === '' || $terms === [] || $cues === []) {
            return false;
        }
        if (!$this->matchesAny($text, $cues)) {
            return false;
        }
        return $this->matchesAny($text, $terms);
    }

    private function matchesAny(string $text, array $needles): bool
    {
        $lowered = mb_strtolower($text);
        foreach ($needles as $needle) {
            $cleaned = trim(mb_strtolower((string)$needle));
            if ($cleaned === '') {
                continue;
            }
            if ($cleaned === '?') {
                if (str_contains($lowered, '?')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/\b' . preg_quote($cleaned, '/') . '\b/u', $lowered) === 1) {
                return true;
            }
        }
        return false;
    }
}
