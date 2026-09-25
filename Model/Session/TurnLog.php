<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session;

use MageOS\AiShoppingAssistant\Api\Turn\TurnLogInterface;

/**
 * A turn log failure must never break the turn it is recording, so record() swallows
 * every insert exception and only warns.
 */
final class TurnLog implements TurnLogInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Turn $resource,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function record(array $row): void
    {
        try {
            $this->resource->insert($row);
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf('turn log insert failed error=%s', $exception->getMessage()));
        }
    }
}
