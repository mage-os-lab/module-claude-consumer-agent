<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface SearchProviderInterface
{
    /**
     * @return int[]
     */
    public function search(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        int $limit
    ): array;
}
