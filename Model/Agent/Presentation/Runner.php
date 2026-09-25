<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation;

use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

/**
 * run() takes a fourth optional $streamId, falling back to the component name when null,
 * so the caller can pass the originating tool_use id through to the ui event.
 */
final class Runner
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry $registry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator $validator,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig
    ) {
    }

    public function run(
        string $name,
        array $input,
        SessionContext $context,
        SessionState $state,
        ?string $streamId = null
    ): ToolOutcome {
        $spec = $this->registry->components()[$name] ?? null;
        if ($spec === null) {
            return ToolOutcome::error("Unknown presentation tool: {$name}");
        }
        $errors = $this->validator->validate($spec->schema, $input, true);
        if ($errors !== []) {
            return ToolOutcome::error("Invalid {$name} payload: " . implode(', ', $errors));
        }
        $config = $this->storeConfig->agent($context->storeId);
        $enrichmentContext = new EnrichmentContext($this->backend, $config, $context, $state);
        try {
            $payload = ($spec->enricher)($input, $enrichmentContext);
        } catch (PresentationRefused $refused) {
            if ($refused->getGate() !== null) {
                return ToolOutcome::held($refused->getGate(), $refused->getMessage());
            }
            return ToolOutcome::error($refused->getMessage());
        }
        $text = trim('Displayed to the customer. ' . implode(' ', $enrichmentContext->notes));
        $event = Event::ui($spec->component, $payload, $streamId ?? $spec->component);
        return ToolOutcome::ok($text, [$event]);
    }
}
