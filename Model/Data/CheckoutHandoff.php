<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\CheckoutHandoffInterface;

final class CheckoutHandoff implements CheckoutHandoffInterface
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $label = null,
        private readonly ?string $seller = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: (string)($data['url'] ?? ''),
            label: isset($data['label']) ? (string)$data['label'] : null,
            seller: isset($data['seller']) ? (string)$data['seller'] : null
        );
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getSeller(): ?string
    {
        return $this->seller;
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'label' => $this->label,
            'seller' => $this->seller,
        ];
    }
}
