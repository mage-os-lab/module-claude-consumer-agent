<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Turn;

interface TurnLogInterface
{
    public function record(array $row): void;
}
