<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session\ResourceModel;

/**
 * Stays non-final because PHPUnit 9.6 mocks it.
 */
class Message
{
    private const TABLE = 'aiagent_message';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function loadBySession(string $sessionId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('session_id = ?', $sessionId)
            ->order('message_id ASC');
        return $connection->fetchAll($select);
    }

    public function insertMany(string $sessionId, array $messages): void
    {
        $connection = $this->resourceConnection->getConnection();
        foreach ($messages as $message) {
            $connection->insert($this->getTable(), [
                'session_id' => $sessionId,
                'role' => (string)$message['role'],
                'content' => json_encode(
                    $message['content'],
                    JSON_UNESCAPED_UNICODE
                        | JSON_UNESCAPED_SLASHES
                        | JSON_INVALID_UTF8_SUBSTITUTE
                        | JSON_THROW_ON_ERROR
                ),
            ]);
        }
    }

    public function deleteBySession(string $sessionId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->getTable(), ['session_id = ?' => $sessionId]);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
