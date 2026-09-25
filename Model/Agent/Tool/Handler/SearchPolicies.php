<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class SearchPolicies implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $query = (string)($input['query'] ?? '');
        $policies = $this->backend->searchPolicies($context, $query);
        $payload = $this->serializer->policies($policies);
        $text = "Policies:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text);
    }
}
