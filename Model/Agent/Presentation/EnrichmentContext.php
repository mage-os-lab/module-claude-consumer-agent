<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation;

final class EnrichmentContext
{
    public function __construct(
        public readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        public readonly \MageOS\AiShoppingAssistant\Model\Agent\AgentConfig $config,
        public readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionContext $context,
        public readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionState $state,
        public array $notes = []
    ) {
    }
}
