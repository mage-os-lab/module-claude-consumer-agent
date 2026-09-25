<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

/**
 * Where the cache breakpoints sit: the static system block, the last tool definition,
 * and a rolling marker on the newest persisted message block for an auto round with
 * two or more outgoing messages. Never mutates the caller's message history.
 */
final class Assembly
{
    private const CACHE_CONTROL = ['type' => 'ephemeral'];

    public function build(
        string $model,
        int $maxTokens,
        string $staticText,
        string $dynamicText,
        array $tools,
        array $toolChoice,
        array $messages,
        bool $rollingBreakpoint,
        array $thinking,
        string $effort
    ): array {
        $request = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => [
                ['type' => 'text', 'text' => $staticText, 'cache_control' => self::CACHE_CONTROL],
                ['type' => 'text', 'text' => $dynamicText],
            ],
            'tools' => $this->withLastToolMarker($tools),
            'tool_choice' => $toolChoice,
            'messages' => $this->requestMessages($messages, $rollingBreakpoint),
            'thinking' => $thinking,
        ];
        if (($thinking['type'] ?? null) === 'adaptive') {
            $request['output_config'] = ['effort' => $effort];
        }
        return $request;
    }

    private function withLastToolMarker(array $tools): array
    {
        if ($tools === []) {
            return $tools;
        }
        $lastIndex = array_key_last($tools);
        $marked = $tools;
        $marked[$lastIndex] = $marked[$lastIndex] + ['cache_control' => self::CACHE_CONTROL];
        return $marked;
    }

    private function requestMessages(array $messages, bool $rollingBreakpoint): array
    {
        if ($messages === []) {
            return [];
        }
        $request = [];
        foreach ($messages as $message) {
            $message = $this->withoutMarker($message);
            $lastIndex = count($request) - 1;
            $mergesIntoPrevious = $lastIndex >= 0
                && ($message['role'] ?? null) === 'user'
                && ($request[$lastIndex]['role'] ?? null) === 'user';
            if ($mergesIntoPrevious) {
                $request[$lastIndex]['content'] = array_merge(
                    $this->blocks($request[$lastIndex]['content'] ?? null),
                    $this->blocks($message['content'] ?? null)
                );
                continue;
            }
            $request[] = $message;
        }
        if (!$rollingBreakpoint || count($request) < 2) {
            return $request;
        }
        $lastIndex = count($request) - 1;
        $content = $this->blocks($request[$lastIndex]['content'] ?? null);
        $blockCount = count($content);
        if ($blockCount > 0) {
            $content[$blockCount - 1] = $content[$blockCount - 1] + ['cache_control' => self::CACHE_CONTROL];
            $request[$lastIndex]['content'] = $content;
        }
        return $request;
    }

    private function withoutMarker(array $message): array
    {
        $content = $message['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return $message;
        }
        $hasMarker = false;
        foreach ($content as $block) {
            if (is_array($block) && array_key_exists('cache_control', $block)) {
                $hasMarker = true;
                break;
            }
        }
        if (!$hasMarker) {
            return $message;
        }
        $stripped = [];
        foreach ($content as $block) {
            if (is_array($block)) {
                unset($block['cache_control']);
            }
            $stripped[] = $block;
        }
        $message['content'] = $stripped;
        return $message;
    }

    private function blocks(mixed $raw): array
    {
        if (is_string($raw)) {
            return [['type' => 'text', 'text' => $raw]];
        }
        return is_array($raw) ? $raw : [];
    }
}
