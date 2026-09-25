<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Exception;

final class InvalidArguments extends \RuntimeException
{
    private readonly array $errors;

    public function __construct(
        string $message,
        array $errors = []
    ) {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function summary(): string
    {
        return implode(', ', $this->errors);
    }
}
