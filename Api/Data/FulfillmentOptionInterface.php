<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface FulfillmentOptionInterface
{
    public const METHOD_DELIVERY = 'delivery';
    public const METHOD_PICKUP = 'pickup';
    public const METHOD_SHIPPING = 'shipping';

    public function getMethod(): string;

    public function getEta(): string;

    public function getFee(): float;

    public function getLocation(): ?string;

    public function toArray(): array;
}
