<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits;

final class SlotLock
{
    private const PREFIX = 'aiagent:turn:';

    public function __construct(
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function name(int $storeId, int $n): string
    {
        return self::PREFIX . $storeId . ':' . $n;
    }

    public function acquire(int $storeId): ?SlotHandle
    {
        $max = $this->storeConfig->agent($storeId)->concurrentTurns;
        for ($n = 1; $n <= $max; $n++) {
            $name = $this->name($storeId, $n);
            if ($this->lockManager->lock($name, 0)) {
                return new SlotHandle($this->lockManager, $name);
            }
        }
        $this->logger->info(sprintf('busy store=%d slots=%d', $storeId, $max));
        return null;
    }
}
