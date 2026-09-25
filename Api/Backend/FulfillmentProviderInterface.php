<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;

interface FulfillmentProviderInterface
{
    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface[]
     */
    public function options(SessionContext $ctx, array $productIds): array;
}
