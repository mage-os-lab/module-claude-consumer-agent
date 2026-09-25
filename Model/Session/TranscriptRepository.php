<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session;

final class TranscriptRepository
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Message $resource,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function load(string $sessionId): array
    {
        $rows = $this->resource->loadBySession($sessionId);
        $messages = [];
        foreach ($rows as $row) {
            $content = json_decode((string)$row['content'], true);
            $messages[] = [
                'role' => (string)$row['role'],
                'content' => is_array($content) ? $content : [],
            ];
        }
        return $messages;
    }

    public function append(string $sessionId, array $messages): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $this->resource->insertMany($sessionId, $messages);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    public function rewrite(string $sessionId, array $messages): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();
        try {
            $this->resource->deleteBySession($sessionId);
            $this->resource->insertMany($sessionId, $messages);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
    }

    public function countTokensEstimate(array $messages): int
    {
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $chars = $json !== false ? strlen($json) : 0;
        return (int)($chars / 4);
    }
}
