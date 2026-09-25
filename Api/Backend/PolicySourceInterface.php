<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface PolicySourceInterface
{
    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\PolicyInterface[]
     */
    public function search(SessionContext $ctx, string $query): array;
}
