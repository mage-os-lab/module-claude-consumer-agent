<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

final class ApiKey
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }
}
