<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly ?string $apiType = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatus(): ?int
    {
        return $this->status;
    }

    public function getApiType(): ?string
    {
        return $this->apiType;
    }
}
