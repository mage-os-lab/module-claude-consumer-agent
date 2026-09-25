<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Data;

interface CategoryMatchInterface
{
    public function getCategoryId(): int;

    public function getName(): string;

    /**
     * @return string[]
     */
    public function getPath(): array;

    public function getProductCount(): int;

    public function getLevel(): int;

    public function toArray(): array;
}
