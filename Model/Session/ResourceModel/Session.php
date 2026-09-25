<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session\ResourceModel;

/**
 * Stays non-final because PHPUnit 9.6 mocks it.
 */
class Session
{
    private const TABLE = 'aiagent_session';

    public function __construct(
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
    }

    public function load(string $id): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('session_id = ?', $id);
        $row = $connection->fetchRow($select);
        return $row !== false ? $row : null;
    }

    public function insert(array $row): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->getTable(), $row);
    }

    public function update(string $id, array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update($this->getTable(), $data, ['session_id = ?' => $id]);
    }

    public function updateWhereVersion(string $id, array $data, int $expectedVersion): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->update(
            $this->getTable(),
            $data,
            ['session_id = ?' => $id, 'version = ?' => $expectedVersion]
        );
    }

    public function delete(string $id): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->delete($this->getTable(), ['session_id = ?' => $id]);
    }

    public function deleteOlderThan(\DateTimeInterface $before, ?int $storeId = null, int $batch = 500): int
    {
        $connection = $this->resourceConnection->getConnection();
        $deleted = 0;
        while (true) {
            $select = $connection->select()
                ->from($this->getTable(), ['session_id'])
                ->where('updated_at < ?', $before->format('Y-m-d H:i:s'))
                ->limit($batch);
            if ($storeId !== null) {
                $select->where('store_id = ?', $storeId);
            }
            $ids = $connection->fetchCol($select);
            if (count($ids) === 0) {
                break;
            }
            $connection->delete($this->getTable(), ['session_id IN (?)' => $ids]);
            $deleted += count($ids);
            if (count($ids) < $batch) {
                break;
            }
        }
        return $deleted;
    }

    public function deleteByCustomer(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->delete($this->getTable(), ['customer_id = ?' => $customerId]);
    }

    public function deleteAll(): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->delete($this->getTable());
    }

    public function countOlderThan(\DateTimeInterface $before): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['count' => 'COUNT(*)'])
            ->where('updated_at < ?', $before->format('Y-m-d H:i:s'));
        return (int)$connection->fetchOne($select);
    }

    public function countByCustomer(int $customerId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['count' => 'COUNT(*)'])
            ->where('customer_id = ?', $customerId);
        return (int)$connection->fetchOne($select);
    }

    public function countAll(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->getTable(), ['count' => 'COUNT(*)']);
        return (int)$connection->fetchOne($select);
    }

    private function getTable(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }
}
