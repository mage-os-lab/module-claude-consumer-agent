<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Backs a per-case AgentConfig override in fixture mode: every path in $values wins over
 * whatever the store actually has configured, and every other path reads as unset so
 * StoreConfig falls back to the AgentConfig defaults instead of reaching a real config
 * store. Not final: Magento generates an interceptor for every ScopeConfigInterface
 * implementation.
 */
class FakeScopeConfig implements ScopeConfigInterface
{
    public function __construct(
        private readonly array $values
    ) {
    }

    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool)($this->values[$path] ?? false);
    }
}
