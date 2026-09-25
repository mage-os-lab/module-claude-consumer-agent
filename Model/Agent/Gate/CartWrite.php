<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Gate;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class CartWrite
{
    private const LOCK_TIMEOUT = 5;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Options $options,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function lockName(string $sessionId): string
    {
        return 'aiagent:cart:' . substr($sessionId, 0, 32);
    }

    public function add(
        SessionContext $ctx,
        SessionState $state,
        AgentConfig $config,
        string $productId,
        int $quantity,
        array $options = []
    ): ToolOutcome {
        $held = $this->provenance->check($state, $productId) ?? $this->options->check($state, $productId, $options);
        if ($held !== null) {
            return $held;
        }
        $requested = max(1, $quantity);
        $cap = $config->maxQuantityPerItem;
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $cart = $this->backend->getCart($ctx);
            $line = $cart->find($productId);
            if ($line === null && count($cart->getItems()) >= $config->maxCartLines) {
                return ToolOutcome::error('The cart is full.');
            }
            $existingQuantity = $line !== null ? $line->getQuantity() : 0;
            $allowed = min($requested, max(0, $cap - $existingQuantity));
            if ($allowed <= 0) {
                return ToolOutcome::error('This item is already at the per-item limit of ' . $cap . '.');
            }
            $cart = $this->backend->addToCart($ctx, $productId, $allowed, $options);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        $capped = $allowed < $requested ? ' (capped at the per-item limit of ' . $cap . ')' : '';
        return ToolOutcome::ok(
            'Added ' . $productId . ' x' . $allowed . $capped . '. Cart now has '
            . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    public function update(
        SessionContext $ctx,
        SessionState $state,
        AgentConfig $config,
        string $productId,
        int $quantity
    ): ToolOutcome {
        $requested = max(1, $quantity);
        $cap = $config->maxQuantityPerItem;
        $applied = min($requested, $cap);
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $held = $this->checkProvenanceOrCartLine($ctx, $state, $productId);
            if ($held !== null) {
                return $held;
            }
            $cart = $this->backend->updateCartItem($ctx, $productId, $applied);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        $capped = $applied < $requested ? ' (capped at the per-item limit of ' . $cap . ')' : '';
        return ToolOutcome::ok(
            'Updated quantity' . $capped . '. Cart now has ' . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    public function remove(SessionContext $ctx, SessionState $state, string $productId): ToolOutcome
    {
        $lockName = $this->lockName($ctx->sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT)) {
            $this->logger->warning('cart lock timed out session=' . $ctx->sessionId . ' lock=' . $lockName);
            return ToolOutcome::error('The cart is busy; try again in a moment.');
        }
        try {
            $held = $this->checkProvenanceOrCartLine($ctx, $state, $productId);
            if ($held !== null) {
                return $held;
            }
            $cart = $this->backend->removeFromCart($ctx, $productId);
        } finally {
            $this->lockManager->unlock($lockName);
        }
        return ToolOutcome::ok(
            'Removed. Cart now has ' . $this->serializer->cartSummary($cart) . '.',
            [Event::cartUpdate($this->serializer->cart($cart))]
        );
    }

    private function checkProvenanceOrCartLine(SessionContext $ctx, SessionState $state, string $productId): ?ToolOutcome
    {
        $held = $this->provenance->check($state, $productId);
        if ($held === null) {
            return null;
        }
        $cart = $this->backend->getCart($ctx);
        foreach ($cart->getItems() as $item) {
            if ($item->getProductId() === $productId) {
                return null;
            }
        }
        $this->logger->warning('cart version conflict session=' . $ctx->sessionId . ' product_id=' . $productId);
        return $held;
    }
}
