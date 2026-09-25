<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool;

use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

/**
 * Turns every presentation extension registered on the presentation registry into a tool
 * definition. The five built-in components are excluded here: CoreToolProvider already
 * emits them from the same registry, so including them again would register duplicates.
 */
final class PresentationToolProvider implements ToolProviderInterface
{
    private const BUILT_IN_NAMES = [
        'present_products',
        'present_comparison',
        'present_order_status',
        'checkout',
        'present_suggestions',
    ];

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry $presentation
    ) {
    }

    /**
     * @return \MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition[]
     */
    public function getTools(AgentConfig $config): array
    {
        $definitions = [];
        foreach ($this->presentation->components() as $name => $component) {
            if (in_array($name, self::BUILT_IN_NAMES, true)) {
                continue;
            }
            $definitions[] = new Definition(
                $component->name,
                $component->description,
                $component->schema,
                'presentation',
                null,
                [],
                false,
                false,
                0
            );
        }
        return $definitions;
    }
}
