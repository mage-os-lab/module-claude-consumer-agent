<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;

final class FulfillmentOption implements FulfillmentOptionInterface
{
    public function __construct(
        private readonly string $method,
        private readonly string $eta,
        private readonly float $fee = 0.0,
        private readonly ?string $location = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            method: (string)($data['method'] ?? self::METHOD_SHIPPING),
            eta: (string)($data['eta'] ?? ''),
            fee: (float)($data['fee'] ?? 0.0),
            location: isset($data['location']) ? (string)$data['location'] : null
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getEta(): string
    {
        return $this->eta;
    }

    public function getFee(): float
    {
        return $this->fee;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'eta' => $this->eta,
            'fee' => $this->fee,
            'location' => $this->location,
        ];
    }
}
