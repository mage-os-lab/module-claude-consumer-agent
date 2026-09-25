<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Tool;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

interface HandlerInterface
{
    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome;
}
