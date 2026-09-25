<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;

final class Suggestions
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer
    ) {
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $suggestions = is_array($input['suggestions'] ?? null) ? $input['suggestions'] : [];
        $chips = $this->sanitizer->chips($suggestions);
        if ($chips === []) {
            throw new PresentationRefused(
                'every suggestion was empty after sanitization; send 1-4 short, plain-text suggestions.'
            );
        }
        return ['suggestions' => $chips];
    }
}
