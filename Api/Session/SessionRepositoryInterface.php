<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Session;

use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

interface SessionRepositoryInterface
{
    public function bind(?string $sessionId, SessionContext $ctx, string $surface = 'overlay'): Binding;

    public function find(?string $sessionId, SessionContext $ctx): ?Binding;

    public function create(SessionContext $ctx, string $surface = 'overlay'): Binding;

    public function save(Binding $binding, SessionState $state): bool;

    public function delete(string $sessionId): void;
}
