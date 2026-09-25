<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class AddToCart implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\CartWrite $cartWrite
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        return $this->cartWrite->add(
            $context,
            $state,
            $config,
            (string)($input['product_id'] ?? ''),
            (int)($input['quantity'] ?? 1),
            is_array($input['options'] ?? null) ? $input['options'] : []
        );
    }
}
