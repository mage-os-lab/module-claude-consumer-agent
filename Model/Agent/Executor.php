<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\InvalidArguments;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\NotOffered;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;

final class Executor
{
    private const DISPLAYED_TEXT = 'Displayed to the customer.';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry $registry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Runner $presentation,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator $validator,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance $provenance,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionContext $context,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\SessionState $state,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\AgentConfig $config
    ) {
    }

    public function dispatch(string $name, array $input, ?string $toolUseId = null): ToolOutcome
    {
        $definition = $this->registry->byName($this->context->storeId, $name);
        if ($definition === null) {
            return ToolOutcome::error('Unknown tool: ' . $name);
        }
        [$args, $label] = $this->splitStatus($definition, $input);
        try {
            if ($definition->getKind() === 'presentation') {
                $outcome = $this->presentation->run($name, $args, $this->context, $this->state, $toolUseId);
            } else {
                $errors = $this->validator->validate($definition->getInputSchema(), $args);
                if ($errors !== []) {
                    throw new InvalidArguments(implode(', ', $errors), $errors);
                }
                foreach ($definition->getProductIdArguments() as $path) {
                    foreach ($this->valuesAt($args, $path) as $productId) {
                        $held = $this->provenance->check($this->state, $productId);
                        if ($held !== null) {
                            return $held->withLabel($label);
                        }
                    }
                }
                $handler = $definition->getHandler();
                if ($handler === null) {
                    throw new \RuntimeException($name . ' has no handler configured');
                }
                $outcome = $handler->handle($args, $this->context, $this->state, $this->config);
                if ($definition->remembersProducts() && $outcome->products !== []) {
                    $this->state->rememberProducts($outcome->products);
                }
            }
        } catch (InvalidArguments $exception) {
            $outcome = ToolOutcome::error(
                $name . ' arguments were invalid: ' . $exception->summary() . ' Adjust and call it again.'
            );
        } catch (Unavailable $exception) {
            $outcome = ToolOutcome::error(
                'Nothing was added: ' . $this->sanitizer->text($exception->getMessage(), 200)
                . '. Tell the customer, offer what the message names as available, and add that only '
                . 'once they choose it.'
            );
        } catch (NotOffered $exception) {
            $outcome = ToolOutcome::error(
                $this->sanitizer->text($exception->getMessage(), 200)
                . ' is not something this store offers; say so plainly.'
            );
        } catch (SignInRequired) {
            $outcome = ToolOutcome::error(
                'This needs a signed-in customer. Ask the customer to sign in and try again.'
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('tool failed', ['tool' => $name, 'exception' => $exception]);
            $outcome = ToolOutcome::error(
                $name . ' is temporarily unavailable. Work with what you already have or let the customer know.'
            );
        }
        return $outcome->withLabel($label)->withArgumentsShown($args);
    }

    public function endsClean(string $name, ToolOutcome $outcome): bool
    {
        $definition = $this->registry->byName($this->context->storeId, $name);
        if ($definition === null) {
            return false;
        }
        return $definition->getKind() === 'presentation'
            && !$outcome->isError
            && $outcome->blocked === null
            && $outcome->resultText === self::DISPLAYED_TEXT;
    }

    private function splitStatus(Definition $def, array $input): array
    {
        if (!$def->takesStatus() || !array_key_exists('status', $input)) {
            return [$input, null];
        }
        $label = $this->sanitizer->label((string)$input['status'], 60);
        unset($input['status']);
        return [$input, $label !== '' ? $label : null];
    }

    private function valuesAt(array $args, string $path): array
    {
        return $this->collect($args, explode('.', $path));
    }

    private function collect(mixed $node, array $segments): array
    {
        if ($segments === []) {
            return is_string($node) || is_int($node) ? [(string)$node] : [];
        }
        $segment = array_shift($segments);
        $isList = str_ends_with($segment, '[]');
        $key = $isList ? substr($segment, 0, -2) : $segment;
        if (!is_array($node) || !array_key_exists($key, $node)) {
            return [];
        }
        $value = $node[$key];
        if (!$isList) {
            return $this->collect($value, $segments);
        }
        if (!is_array($value)) {
            return [];
        }
        $results = [];
        foreach ($value as $item) {
            $results = array_merge($results, $this->collect($item, $segments));
        }
        return $results;
    }
}
