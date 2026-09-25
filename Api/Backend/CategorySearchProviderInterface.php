<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface CategorySearchProviderInterface
{
    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\CategoryMatchInterface[]
     */
    public function search(SessionContext $ctx, string $keywords, int $limit): array;
}
