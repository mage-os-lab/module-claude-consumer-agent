<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

/**
 * The first entry in the injected provider pool is the core provider: its tools keep
 * the order it hands them back in. Every other provider is an extension: its tools are
 * pooled and sorted by (sortOrder, name) behind the core tools.
 */
final class Registry
{
    private array $definitionsByStore = [];

    private array $byNameByStore = [];

    /**
     * @param \MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig
    ) {
    }

    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    public function definitions(int $storeId): array
    {
        return $this->definitionsByStore[$storeId] ??= $this->buildDefinitions($storeId);
    }

    public function apiDefinitions(int $storeId): array
    {
        return array_map(
            static fn (Definition $definition): array => $definition->apiDefinition(),
            $this->definitions($storeId)
        );
    }

    public function byName(int $storeId, string $name): ?Definition
    {
        if (!isset($this->byNameByStore[$storeId])) {
            $map = [];
            foreach ($this->definitions($storeId) as $definition) {
                $map[$definition->getName()] = $definition;
            }
            $this->byNameByStore[$storeId] = $map;
        }
        return $this->byNameByStore[$storeId][$name] ?? null;
    }

    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    private function buildDefinitions(int $storeId): array
    {
        $config = $this->storeConfig->agent($storeId);
        $absent = $this->absentTools($config);
        $core = [];
        $extensions = [];
        $isCoreProvider = true;
        foreach ($this->providers as $provider) {
            foreach ($provider->getTools($config) as $definition) {
                if (in_array($definition->getName(), $absent, true)) {
                    continue;
                }
                if ($isCoreProvider) {
                    $core[] = $definition;
                } else {
                    $extensions[] = $definition;
                }
            }
            $isCoreProvider = false;
        }
        usort(
            $extensions,
            static fn (Definition $a, Definition $b): int =>
                [$a->getSortOrder(), $a->getName()] <=> [$b->getSortOrder(), $b->getName()]
        );
        $definitions = array_merge($core, $extensions);
        $this->assertNoDuplicates($definitions);
        return $definitions;
    }

    private function absentTools(AgentConfig $config): array
    {
        $absent = [];
        if (!$config->enableOrders) {
            $absent[] = 'get_orders';
            $absent[] = 'get_order_status';
            $absent[] = 'present_order_status';
        }
        if (!$config->enablePolicies) {
            $absent[] = 'search_policies';
        }
        if (!$config->enableFulfillment) {
            $absent[] = 'get_fulfillment_options';
        }
        return $absent;
    }

    private function assertNoDuplicates(array $definitions): void
    {
        $seen = [];
        foreach ($definitions as $definition) {
            $name = $definition->getName();
            if (isset($seen[$name])) {
                throw new \LogicException('Duplicate tool name: ' . $name);
            }
            $seen[$name] = true;
        }
    }
}
