<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Prompt;

interface CoreFactProviderInterface
{
    public function line(int $storeId): ?string;
}
