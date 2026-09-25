<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Session;

use MageOS\AiShoppingAssistant\Api\Session\SessionRepositoryInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;

/**
 * A signed-in customer may claim a guest row of the same quote, so a guest who signs in keeps the session.
 */
final class Repository implements SessionRepositoryInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session $resource,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\IdGenerator $idGenerator,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function bind(?string $sessionId, SessionContext $ctx, string $surface = 'overlay'): Binding
    {
        $binding = $this->find($sessionId, $ctx);
        if ($binding === null) {
            return $this->create($ctx, $surface);
        }
        if ($binding->row['customer_id'] === null && $ctx->customerId !== null) {
            $this->resource->update($binding->sessionId, ['customer_id' => $ctx->customerId]);
            $binding->row['customer_id'] = $ctx->customerId;
        }
        return $binding;
    }

    public function find(?string $sessionId, SessionContext $ctx): ?Binding
    {
        if ($sessionId === null || !$this->isValidSessionId($sessionId)) {
            return null;
        }
        $row = $this->resource->load($sessionId);
        if ($row === null) {
            return null;
        }
        if (!$this->isOwned($row, $ctx) || (int)$row['store_id'] !== $ctx->storeId) {
            return null;
        }
        return new Binding(
            $sessionId,
            $row,
            SessionState::fromJson((string)$row['state']),
            false,
            (int)$row['version'],
            $ctx
        );
    }

    public function create(SessionContext $ctx, string $surface = 'overlay'): Binding
    {
        $sessionId = $this->idGenerator->generate();
        $row = [
            'session_id' => $sessionId,
            'customer_id' => $ctx->customerId,
            'quote_id' => $ctx->quoteId,
            'store_id' => $ctx->storeId,
            'surface' => $surface,
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ];
        $this->resource->insert($row);
        return new Binding($sessionId, $row, new SessionState(), true, 0, $ctx);
    }

    public function save(Binding $binding, SessionState $state): bool
    {
        $turns = $binding->row !== null ? (int)($binding->row['turns'] ?? 0) : 0;
        $newTurns = $turns + 1;
        $newVersion = $binding->expectedVersion + 1;
        $affected = $this->resource->updateWhereVersion(
            $binding->sessionId,
            [
                'state' => $state->toJson(),
                'version' => $newVersion,
                'turns' => $newTurns,
            ],
            $binding->expectedVersion
        );
        if ($affected === 0) {
            $this->logger->warning(sprintf('session version conflict session=%s', $this->digest($binding->sessionId)));
            return false;
        }
        $binding->expectedVersion = $newVersion;
        if ($binding->row !== null) {
            $binding->row['turns'] = $newTurns;
            $binding->row['version'] = $newVersion;
        }
        return true;
    }

    public function delete(string $sessionId): void
    {
        $this->resource->delete($sessionId);
    }

    private function isValidSessionId(string $sessionId): bool
    {
        return strlen($sessionId) === 64 && ctype_xdigit($sessionId);
    }

    private function isOwned(array $row, SessionContext $ctx): bool
    {
        $rowCustomerId = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
        $rowQuoteId = $row['quote_id'] !== null ? (int)$row['quote_id'] : null;
        if ($ctx->customerId !== null) {
            if ($rowCustomerId !== null) {
                return $rowCustomerId === $ctx->customerId;
            }
            return $rowQuoteId === $ctx->quoteId;
        }
        return $rowCustomerId === null && $rowQuoteId === $ctx->quoteId;
    }

    private function digest(string $sessionId): string
    {
        return substr(sha1($sessionId), 0, 12);
    }
}
