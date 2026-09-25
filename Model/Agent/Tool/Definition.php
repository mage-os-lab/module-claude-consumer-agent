<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool;

use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;

final class Definition
{
    public const STATUS_MAX_LENGTH = 60;
    public const STATUS_DESCRIPTION = 'A few plain words the customer sees while this runs';

    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private readonly string $kind,
        private readonly ?\MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface $handler,
        private readonly array $productIdArguments,
        private readonly bool $remembersProducts,
        private readonly bool $takesStatus,
        private readonly int $sortOrder
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHandler(): ?HandlerInterface
    {
        return $this->handler;
    }

    public function getProductIdArguments(): array
    {
        return $this->productIdArguments;
    }

    public function remembersProducts(): bool
    {
        return $this->remembersProducts;
    }

    public function takesStatus(): bool
    {
        return $this->takesStatus;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function apiDefinition(): array
    {
        $inputSchema = $this->inputSchema;
        $properties = $inputSchema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array)$properties;
        }
        if ($this->takesStatus) {
            $properties = [
                'status' => [
                    'type' => 'string',
                    'maxLength' => self::STATUS_MAX_LENGTH,
                    'description' => self::STATUS_DESCRIPTION,
                ],
            ] + $properties;
        }
        $inputSchema['properties'] = $properties === [] ? new \stdClass() : $properties;
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $inputSchema,
        ];
    }
}
