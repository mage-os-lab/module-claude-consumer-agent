<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session\ResourceModel;

/**
 * Stays non-final because PHPUnit 9.6 mocks it.
 */
class Turn
{
    private const TABLE = 'aiagent_turn';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function insert(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->getTable(), $row);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
