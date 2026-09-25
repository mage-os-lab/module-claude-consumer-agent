<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

use DateTimeImmutable;

final class SessionContext
{
    public function __construct(
        public readonly string $sessionId,
        public readonly ?int $customerId,
        public readonly int $quoteId,
        public readonly int $storeId,
        public readonly \MageOS\AiShoppingAssistant\Api\Data\PageContextInterface $page,
        public readonly \DateTimeImmutable $now
    ) {
    }

    public function clockHour(): string
    {
        $hour = (int)$this->now->format('H');
        return $this->now->setTime($hour, 0, 0)->format(DateTimeImmutable::ATOM);
    }
}
