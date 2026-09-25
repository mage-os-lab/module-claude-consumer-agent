<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session;

final class Binding
{
    public function __construct(
        public readonly string $sessionId,
        public ?array $row,
        public readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionState $state,
        public readonly bool $isNew,
        public int $expectedVersion,
        public readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionContext $context
    ) {
    }
}
