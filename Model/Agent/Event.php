<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

final class Event
{
    public const TYPE_TEXT_DELTA = 'text_delta';
    public const TYPE_TOOL_CALL = 'tool_call';
    public const TYPE_TOOL_RESULT = 'tool_result';
    public const TYPE_UI = 'ui';
    public const TYPE_CART_UPDATE = 'cart_update';
    public const TYPE_PROGRESS = 'progress';
    public const TYPE_TURN_COMPLETE = 'turn_complete';
    public const TYPE_ERROR = 'error';

    public function __construct(
        public readonly string $type,
        public readonly array $data
    ) {
    }

    public static function textDelta(string $text): self
    {
        return new self(self::TYPE_TEXT_DELTA, ['text' => $text]);
    }

    public static function toolCall(string $tool, string $id, array $input, ?string $label): self
    {
        $data = ['tool' => $tool, 'id' => $id, 'input' => $input];
        if ($label !== null) {
            $data['label'] = $label;
        }
        return new self(self::TYPE_TOOL_CALL, $data);
    }

    public static function toolResult(
        string $tool,
        string $id,
        string $summary,
        bool $isError,
        string $status,
        ?string $reason = null,
        ?string $excerpt = null
    ): self {
        $data = [
            'tool' => $tool,
            'id' => $id,
            'summary' => $summary,
            'is_error' => $isError,
            'status' => $status,
        ];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }
        if ($excerpt !== null) {
            $data['excerpt'] = $excerpt;
        }
        return new self(self::TYPE_TOOL_RESULT, $data);
    }

    public static function ui(string $component, array $payload, string $streamId): self
    {
        return new self(self::TYPE_UI, ['component' => $component, 'payload' => $payload, 'stream_id' => $streamId]);
    }

    public static function cartUpdate(array $cart): self
    {
        return new self(self::TYPE_CART_UPDATE, ['cart' => $cart]);
    }

    public static function progress(string $message): self
    {
        return new self(self::TYPE_PROGRESS, ['message' => $message]);
    }

    public static function turnComplete(
        string $stopReason,
        array $usage,
        int $elapsedMs,
        int $resultsCleared,
        ?string $session = null
    ): self {
        $data = [
            'stop_reason' => $stopReason,
            'usage' => $usage,
            'elapsed_ms' => $elapsedMs,
            'results_cleared' => $resultsCleared,
        ];
        if ($session !== null) {
            $data['session'] = $session;
        }
        return new self(self::TYPE_TURN_COMPLETE, $data);
    }

    public static function error(string $message, ?int $retryAfter = null, ?string $kind = null): self
    {
        $data = ['message' => $message];
        if ($retryAfter !== null) {
            $data['retry_after'] = $retryAfter;
        }
        if ($kind !== null) {
            $data['kind'] = $kind;
        }
        return new self(self::TYPE_ERROR, $data);
    }

    public function toArray(): array
    {
        return ['type' => $this->type, 'data' => $this->data];
    }
}
