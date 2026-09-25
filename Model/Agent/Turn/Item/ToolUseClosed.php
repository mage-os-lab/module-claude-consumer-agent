<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn\Item;

final class ToolUseClosed
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input
    ) {
    }
}
