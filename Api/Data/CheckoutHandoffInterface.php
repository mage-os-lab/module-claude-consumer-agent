<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface CheckoutHandoffInterface
{
    public function getUrl(): string;

    public function getLabel(): ?string;

    public function getSeller(): ?string;

    public function toArray(): array;
}
