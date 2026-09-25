<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class LoadSkill implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry $registry
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $skillName = (string)($input['skill_name'] ?? '');
        $body = $this->registry->body($skillName);
        if ($body === null) {
            return ToolOutcome::error(
                "No skill named {$skillName}. Available: " . implode(', ', $this->registry->names()) . '.'
            );
        }
        return ToolOutcome::ok($body);
    }
}
