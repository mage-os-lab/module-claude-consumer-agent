<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception\ApiStreamError;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\UnreadableToolInput;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;

final class StreamedRound
{
    private array $blocks = [];

    private array $usage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_creation_input_tokens' => 0,
    ];

    private ?string $stopReason = null;

    private ?string $messageId = null;

    public function feed(RawEvent $raw): array
    {
        return match ($raw->type) {
            'message_start' => $this->onMessageStart($raw->data),
            'content_block_start' => $this->onBlockStart($raw->data),
            'content_block_delta' => $this->onBlockDelta($raw->data),
            'content_block_stop' => $this->onBlockStop($raw->data),
            'message_delta' => $this->onMessageDelta($raw->data),
            'error' => $this->onError($raw->data),
            default => [],
        };
    }

    public function assistantMessage(): ?array
    {
        $content = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'thinking' && ($block['signature'] ?? '') !== '') {
                $content[] = [
                    'type' => 'thinking',
                    'thinking' => $block['thinking'] ?? '',
                    'signature' => $block['signature'],
                ];
                continue;
            }
            if ($block['type'] === 'text' && ($block['text'] ?? '') !== '') {
                $content[] = ['type' => 'text', 'text' => $block['text']];
                continue;
            }
            if ($block['type'] === 'tool_use') {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }
        return $content !== [] ? ['role' => 'assistant', 'content' => $content] : null;
    }

    public function toolUses(): array
    {
        $uses = [];
        foreach ($this->blocks as $block) {
            if ($block['type'] === 'tool_use') {
                $uses[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }
        return $uses;
    }

    public function usage(): array
    {
        return $this->usage;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    private function onMessageStart(array $data): array
    {
        $message = $data['message'] ?? [];
        if (isset($message['id'])) {
            $this->messageId = (string)$message['id'];
        }
        $this->takeUsage(is_array($message['usage'] ?? null) ? $message['usage'] : []);
        return [];
    }

    private function onBlockStart(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index)) {
            return [];
        }
        $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
        $type = (string)($block['type'] ?? '');
        $entry = ['type' => $type, 'closed' => false];
        if ($type === 'text') {
            $entry['text'] = '';
        } elseif ($type === 'thinking') {
            $entry['thinking'] = '';
            $entry['signature'] = '';
        } elseif ($type === 'tool_use') {
            $entry['id'] = (string)($block['id'] ?? '');
            $entry['name'] = (string)($block['name'] ?? '');
            $entry['inputBuffer'] = '';
        }
        $this->blocks[$index] = $entry;
        return [];
    }

    private function onBlockDelta(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index) || !isset($this->blocks[$index])) {
            return [];
        }
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        $deltaType = (string)($delta['type'] ?? '');
        if ($deltaType === 'text_delta') {
            $text = (string)($delta['text'] ?? '');
            $this->blocks[$index]['text'] = ($this->blocks[$index]['text'] ?? '') . $text;
            return $text !== '' ? [new TextDelta($text)] : [];
        }
        if ($deltaType === 'input_json_delta') {
            $this->blocks[$index]['inputBuffer'] = ($this->blocks[$index]['inputBuffer'] ?? '')
                . (string)($delta['partial_json'] ?? '');
            return [];
        }
        if ($deltaType === 'thinking_delta') {
            $this->blocks[$index]['thinking'] = ($this->blocks[$index]['thinking'] ?? '')
                . (string)($delta['thinking'] ?? '');
            return [];
        }
        if ($deltaType === 'signature_delta') {
            $this->blocks[$index]['signature'] = (string)($delta['signature'] ?? '');
            return [];
        }
        return [];
    }

    private function onBlockStop(array $data): array
    {
        $index = $data['index'] ?? null;
        if (!is_int($index) || !isset($this->blocks[$index])) {
            return [];
        }
        $this->blocks[$index]['closed'] = true;
        if ($this->blocks[$index]['type'] !== 'tool_use') {
            return [];
        }
        $buffer = $this->blocks[$index]['inputBuffer'] ?? '';
        $parsed = $buffer === '' ? [] : json_decode($buffer, true);
        $id = $this->blocks[$index]['id'];
        $name = $this->blocks[$index]['name'];
        if (is_array($parsed)) {
            $this->blocks[$index]['input'] = $parsed;
            return [new ToolUseClosed($id, $name, $parsed)];
        }
        $this->blocks[$index]['input'] = [];
        return [new UnreadableToolInput($id, $name)];
    }

    private function onMessageDelta(array $data): array
    {
        $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
        if (isset($delta['stop_reason']) && $delta['stop_reason'] !== null) {
            $this->stopReason = (string)$delta['stop_reason'];
        }
        $this->takeUsage(is_array($data['usage'] ?? null) ? $data['usage'] : []);
        return [];
    }

    private function onError(array $data): array
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        throw new ApiStreamError(
            (string)($error['type'] ?? 'error'),
            (string)($error['message'] ?? 'stream error')
        );
    }

    private function takeUsage(array $usage): void
    {
        foreach (array_keys($this->usage) as $key) {
            if (isset($usage[$key]) && is_int($usage[$key])) {
                $this->usage[$key] = $usage[$key];
            }
        }
    }
}
