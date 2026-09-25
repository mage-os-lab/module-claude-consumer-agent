<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface ProductDetailsInterface extends ProductInterface
{
    public function getLongDescription(): ?string;

    public function getSpecs(): array;

    /**
     * @return \MageOS\AiShoppingAssistant\Api\Data\ProductInterface[]
     */
    public function getVariants(): array;

    public function getNote(): ?string;

    public function withNote(?string $note): self;
}
