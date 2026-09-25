<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Message;

/**
 * A drop-in replacement for the message resource model, keyed by session id, so a
 * TranscriptRepository built with it never touches the aiagent_message table.
 */
final class InMemoryTranscripts extends Message
{
    private array $rows = [];

    public function __construct()
    {
    }

    public function loadBySession(string $sessionId): array
    {
        return $this->rows[$sessionId] ?? [];
    }

    public function insertMany(string $sessionId, array $messages): void
    {
        foreach ($messages as $message) {
            $this->rows[$sessionId][] = [
                'session_id' => $sessionId,
                'role' => (string)$message['role'],
                'content' => (string)json_encode($message['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
    }

    public function deleteBySession(string $sessionId): void
    {
        unset($this->rows[$sessionId]);
    }
}
