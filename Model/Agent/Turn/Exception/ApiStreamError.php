<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception;

/**
 * Raised when the Messages API sends an 'error' event inside an otherwise open stream.
 * Not part of Model\Client\Exception\*: those model a failed HTTP call, this models a
 * stream that started and then reported failure mid-flight.
 */
final class ApiStreamError extends \RuntimeException
{
    public function __construct(
        private readonly string $apiType,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getApiType(): string
    {
        return $this->apiType;
    }
}
