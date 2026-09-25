<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client\Exception;

final class RateLimited extends ApiException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter,
        ?int $status = null,
        ?string $apiType = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $status, $apiType, $previous);
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
