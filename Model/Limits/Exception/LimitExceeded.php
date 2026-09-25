<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits\Exception;

final class LimitExceeded extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter
    ) {
        parent::__construct($message);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
