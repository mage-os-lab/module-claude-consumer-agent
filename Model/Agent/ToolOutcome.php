<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

final class ToolOutcome
{
    public function __construct(
        public readonly string $resultText,
        public readonly bool $isError,
        public readonly ?string $blocked,
        public readonly array $events,
        public readonly ?string $label,
        public readonly array $argumentsShown,
        public readonly array $products
    ) {
    }

    public static function ok(string $text, array $events = [], array $products = []): self
    {
        return new self($text, false, null, $events, null, [], $products);
    }

    public static function error(string $text): self
    {
        return new self($text, true, null, [], null, [], []);
    }

    public static function held(string $gate, string $text): self
    {
        return new self($text, false, $gate, [], null, [], []);
    }

    public function withLabel(?string $label): self
    {
        return new self(
            $this->resultText,
            $this->isError,
            $this->blocked,
            $this->events,
            $label,
            $this->argumentsShown,
            $this->products
        );
    }

    public function withArgumentsShown(array $argumentsShown): self
    {
        return new self(
            $this->resultText,
            $this->isError,
            $this->blocked,
            $this->events,
            $this->label,
            $argumentsShown,
            $this->products
        );
    }
}
