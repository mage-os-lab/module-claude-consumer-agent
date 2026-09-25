<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use MageOS\AiShoppingAssistant\Api\Session\SessionRepositoryInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

/**
 * Session persistence for the eval runner: every save lands in an in-memory map instead
 * of the aiagent_session table, so a case never writes to the database.
 */
final class InMemorySessions implements SessionRepositoryInterface
{
    private array $states = [];

    public function bind(?string $sessionId, SessionContext $ctx, string $surface = 'overlay'): Binding
    {
        return $this->create($ctx, $surface);
    }

    public function find(?string $sessionId, SessionContext $ctx): ?Binding
    {
        return null;
    }

    public function create(SessionContext $ctx, string $surface = 'overlay'): Binding
    {
        return new Binding($ctx->sessionId, null, new SessionState(), true, 0, $ctx);
    }

    public function save(Binding $binding, SessionState $state): bool
    {
        $this->states[$binding->sessionId] = $state;
        $binding->expectedVersion++;
        return true;
    }

    public function delete(string $sessionId): void
    {
        unset($this->states[$sessionId]);
    }
}
