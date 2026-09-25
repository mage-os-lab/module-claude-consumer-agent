<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation;

final class Component
{
    public function __construct(
        public readonly string $name,
        public readonly string $component,
        public readonly array $schema,
        public readonly string $description,
        /** @var callable(array, EnrichmentContext): array */
        public readonly mixed $enricher,
        public readonly string $template
    ) {
    }
}
