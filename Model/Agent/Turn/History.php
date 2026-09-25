<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use MageOS\AiShoppingAssistant\Model\Agent\Executor;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class History
{
    private const INTERRUPTED_TEXT = 'Interrupted before this tool finished.';

    private const CLEARED_TEXT = '[cleared]';

    private const PROTECTED_ROUNDS = 2;

    private const CHIPS_TOOL = 'present_suggestions';

    private array $messages;

    public function __construct(
        array $messages,
        private readonly int $firstNewIndex
    ) {
        $this->messages = $messages;
    }

    public function append(array $message): void
    {
        $this->messages[] = $message;
    }

    public function all(): array
    {
        return $this->messages;
    }

    public function newMessages(): array
    {
        return array_slice($this->messages, $this->firstNewIndex);
    }

    public function isFirstTurn(): bool
    {
        return $this->firstNewIndex === 0;
    }

    public function closeOpenToolUses(array $settled): void
    {
        if ($this->messages === []) {
            return;
        }
        $lastIndex = count($this->messages) - 1;
        $last = $this->messages[$lastIndex];
        if (($last['role'] ?? null) !== 'assistant') {
            return;
        }
        $content = is_array($last['content'] ?? null) ? $last['content'] : [];
        $ids = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && isset($block['id'])) {
                $ids[] = (string)$block['id'];
            }
        }
        if ($ids === []) {
            return;
        }
        $blocks = [];
        foreach ($ids as $id) {
            $outcome = $settled[$id] ?? null;
            $blocks[] = $outcome instanceof ToolOutcome
                ? [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => $outcome->resultText,
                    'is_error' => $outcome->isError,
                ]
                : [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => self::INTERRUPTED_TEXT,
                    'is_error' => true,
                ];
        }
        $this->append(['role' => 'user', 'content' => $blocks]);
    }

    public function compact(int $lastPromptTokens, int $compactAboveTokens): int
    {
        if ($compactAboveTokens <= 0 || $lastPromptTokens < $compactAboveTokens) {
            return 0;
        }
        $resultIndexes = [];
        foreach ($this->messages as $index => $message) {
            if ($this->hasToolResult($message)) {
                $resultIndexes[] = $index;
            }
        }
        $protected = array_flip(array_slice($resultIndexes, -self::PROTECTED_ROUNDS));
        $size = $this->encodedSize($this->messages);
        $target = intdiv($size, 2);
        $cleared = 0;
        foreach ($resultIndexes as $index) {
            if (isset($protected[$index])) {
                continue;
            }
            if ($size <= $target) {
                break;
            }
            $content = $this->messages[$index]['content'];
            foreach ($content as $blockIndex => $block) {
                if ($size <= $target) {
                    break;
                }
                if (!is_array($block) || ($block['type'] ?? null) !== 'tool_result') {
                    continue;
                }
                $current = $block['content'] ?? null;
                if (!is_string($current) || $current === self::CLEARED_TEXT) {
                    continue;
                }
                $delta = strlen($current) - strlen(self::CLEARED_TEXT);
                if ($delta <= 0) {
                    continue;
                }
                $content[$blockIndex]['content'] = self::CLEARED_TEXT;
                $size -= $delta;
                $cleared++;
            }
            $this->messages[$index]['content'] = $content;
        }
        return $cleared;
    }

    public function roundClosesTurn(array $toolUses, array $settled, Executor $executor): bool
    {
        if ($toolUses === []) {
            return false;
        }
        $hasChips = false;
        foreach ($toolUses as $toolUse) {
            $name = (string)($toolUse['name'] ?? '');
            $id = (string)($toolUse['id'] ?? '');
            if ($name === self::CHIPS_TOOL) {
                $hasChips = true;
            }
            $outcome = $settled[$id] ?? null;
            if (!$outcome instanceof ToolOutcome || !$executor->endsClean($name, $outcome)) {
                return false;
            }
        }
        return $hasChips;
    }

    private function hasToolResult(array $message): bool
    {
        $content = $message['content'] ?? null;
        if (!is_array($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                return true;
            }
        }
        return false;
    }

    private function encodedSize(array $messages): int
    {
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? strlen($json) : 0;
    }
}
