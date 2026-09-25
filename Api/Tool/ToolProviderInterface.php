<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Tool;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

interface ToolProviderInterface
{
    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    public function getTools(AgentConfig $config): array;
}
