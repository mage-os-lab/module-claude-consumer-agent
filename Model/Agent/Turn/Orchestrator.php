<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use Generator;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception\ApiStreamError;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\UnreadableToolInput;
use MageOS\AiShoppingAssistant\Model\Client\Exception\BadRequest;
use MageOS\AiShoppingAssistant\Model\Client\Exception\RateLimited;
use MageOS\AiShoppingAssistant\Model\Client\Exception\ServerError;
use MageOS\AiShoppingAssistant\Model\Client\Exception\Transport;
use MageOS\AiShoppingAssistant\Model\Client\Exception\Unauthorized;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

/**
 * Not final so PHPUnit 9.6 can mock it.
 */
class Orchestrator
{
    private const UNREADABLE_INPUT_TEXT = 'The arguments for this call did not arrive as valid JSON, '
        . 'so it was not run. Send the call again.';

    private const REFUSAL_MESSAGE = 'I cannot help with that request.';

    private const UNAVAILABLE_MESSAGE = 'The assistant is not available right now.';

    private const BUSY_MESSAGE = 'The assistant is busy. Please try again in a few seconds.';

    private const SUMMARY_MAX_CHARS = 200;

    private const EXCERPT_MAX_CHARS = 1200;

    private const ROUND_RETRY_DELAY_SECONDS = 0.75;

    private const RETRYABLE_STREAM_ERROR_TYPES = ['overloaded_error', 'api_error'];

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface $client,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem $staticSystem,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext $dynamicContext,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly $assembly,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote $pageNote,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry $toolRegistry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\ExecutorFactory $executorFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Grounding\Rules $rules,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository $transcripts,
        private readonly \MageOS\AiShoppingAssistant\Api\Session\SessionRepositoryInterface $sessions,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory $streamedRoundFactory,
        private readonly \MageOS\AiShoppingAssistant\Api\Turn\TurnLogInterface $turnLog,
        private readonly \MageOS\AiShoppingAssistant\Model\Client\Sleeper $sleeper
    ) {
    }

    public function streamTurn(
        Binding $binding,
        string $message,
        SessionContext $context,
        ?callable $onWaiting = null
    ): Generator {
        $config = $this->storeConfig->agent($context->storeId);
        $state = $binding->state;
        $state->rememberCustomerText($message);

        $loaded = $this->transcripts->load($binding->sessionId);
        $history = new History($loaded, count($loaded));
        $pageNote = $this->pageNote->text($context->page, $state->lastPage);
        $userContent = $pageNote !== null
            ? [['type' => 'text', 'text' => $pageNote], ['type' => 'text', 'text' => $message]]
            : [['type' => 'text', 'text' => $message]];
        $history->append(['role' => 'user', 'content' => $userContent]);
        $state->lastPage = $context->page->toArray();

        $turnStart = microtime(true);
        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'cache_creation_input_tokens' => 0,
        ];
        $stopReason = null;
        $deadline = $turnStart + $config->turnWallClock;

        try {
            $prefs = $this->backend->getPreferences($context);
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'preferences prefetch failed session=%s error=%s',
                $this->digest($binding->sessionId),
                $exception->getMessage()
            ));
            $prefs = null;
        }
        try {
            $cart = $this->backend->getCart($context);
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'cart prefetch failed session=%s error=%s',
                $this->digest($binding->sessionId),
                $exception->getMessage()
            ));
            $cart = null;
        }

        $dynamic = $this->dynamicContext->build($prefs, $cart, $context->page, $context->clockHour());
        $static = $this->staticSystem->text($context->storeId);
        $tools = $this->toolRegistry->apiDefinitions($context->storeId);
        $executor = $this->executorFactory->create(['context' => $context, 'state' => $state, 'config' => $config]);

        $forced = $this->rules->firstForcedTool(
            $config,
            $message,
            $state,
            $context->page,
            $history->isFirstTurn(),
            $context->storeId
        );

        $settled = [];
        $lastPromptTokens = 0;
        $cleared = 0;
        $rounds = 0;

        try {
            for ($round = 0; $round <= $config->maxToolIterations; $round++) {
                $now = microtime(true);
                $forceText = $round === $config->maxToolIterations || $now >= $deadline;
                if ($forceText) {
                    $toolChoice = ['type' => 'none'];
                } elseif ($round === 0 && $forced !== null) {
                    $toolChoice = ['type' => 'tool', 'name' => $forced];
                } else {
                    $toolChoice = ['type' => 'auto'];
                }
                $thinking = $config->thinkingEffort === 'off' ? ['type' => 'disabled'] : ['type' => 'adaptive'];
                $request = $this->assembly->build(
                    $config->modelId,
                    $config->maxTokens,
                    $static,
                    $dynamic,
                    $tools,
                    $toolChoice,
                    $history->all(),
                    $toolChoice['type'] === 'auto',
                    $thinking,
                    $config->thinkingEffort
                );

                $callStart = microtime(true);
                $aborted = false;
                $rounds++;
                $retried = false;
                while (true) {
                    $streamed = $this->streamedRoundFactory->create();
                    $emitted = false;
                    try {
                        foreach ($this->client->stream($request, $onWaiting, $context->storeId) as $rawEvent) {
                            foreach ($streamed->feed($rawEvent) as $item) {
                                $emitted = true;
                                if ($item instanceof TextDelta) {
                                    yield Event::textDelta($item->text);
                                    continue;
                                }
                                if ($item instanceof ToolUseClosed) {
                                    $outcome = $executor->dispatch($item->name, $item->input, $item->id);
                                    $settled[$item->id] = $outcome;
                                    yield Event::toolCall(
                                        $item->name,
                                        $item->id,
                                        $outcome->argumentsShown,
                                        $outcome->label
                                    );
                                    foreach ($outcome->events as $event) {
                                        yield $event;
                                    }
                                    yield $this->toolResultEvent($item->name, $item->id, $outcome);
                                    continue;
                                }
                                if ($item instanceof UnreadableToolInput) {
                                    $outcome = ToolOutcome::error(self::UNREADABLE_INPUT_TEXT);
                                    $settled[$item->id] = $outcome;
                                    yield $this->toolResultEvent($item->name, $item->id, $outcome);
                                }
                            }
                        }
                    } catch (Unauthorized|BadRequest $exception) {
                        $this->logger->error(sprintf(
                            'model call failed session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $this->describe($exception)
                        ));
                        yield Event::error(self::UNAVAILABLE_MESSAGE);
                        $stopReason = 'error';
                        $aborted = true;
                    } catch (RateLimited $exception) {
                        $this->logger->warning(sprintf(
                            'model call rate limited session=%s round=%d',
                            $this->digest($binding->sessionId),
                            $round
                        ));
                        yield Event::error(self::BUSY_MESSAGE, $exception->getRetryAfter());
                        $stopReason = 'error';
                        $aborted = true;
                    } catch (ServerError|Transport|ApiStreamError $exception) {
                        if (!$retried && !$emitted && $this->isWorthRetrying($exception)) {
                            $this->logger->warning(sprintf(
                                'model call retried session=%s round=%d error=%s',
                                $this->digest($binding->sessionId),
                                $round,
                                $this->describe($exception)
                            ));
                            $retried = true;
                            $this->sleeper->sleep(self::ROUND_RETRY_DELAY_SECONDS);
                            continue;
                        }
                        $this->logger->warning(sprintf(
                            'model call failed session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $this->describe($exception)
                        ));
                        yield Event::error(self::BUSY_MESSAGE);
                        $stopReason = 'error';
                        $aborted = true;
                    }
                    break;
                }

                $roundUsage = $streamed->usage();
                $usage = $this->accumulateUsage($usage, $roundUsage);
                $assistant = $streamed->assistantMessage();
                if ($assistant !== null) {
                    $history->append($assistant);
                }
                $toolUses = $streamed->toolUses();

                if ($aborted) {
                    break;
                }

                $lastPromptTokens = $roundUsage['input_tokens']
                    + $roundUsage['cache_read_input_tokens']
                    + $roundUsage['cache_creation_input_tokens'];
                $rawStopReason = $streamed->stopReason();
                $stopReason = $rawStopReason === 'stop_sequence' ? 'end_turn' : $rawStopReason;
                $this->logger->info(sprintf(
                    'model call session=%s round=%d model=%s stop=%s input=%d cache_read=%d '
                        . 'cache_write=%d output=%d elapsed_ms=%d',
                    $this->digest($binding->sessionId),
                    $round,
                    $config->modelId,
                    (string)$stopReason,
                    $roundUsage['input_tokens'],
                    $roundUsage['cache_read_input_tokens'],
                    $roundUsage['cache_creation_input_tokens'],
                    $roundUsage['output_tokens'],
                    (int)round((microtime(true) - $callStart) * 1000)
                ));

                if ($stopReason === 'refusal') {
                    yield Event::error(self::REFUSAL_MESSAGE);
                    break;
                }
                if ($stopReason === 'max_tokens' && $toolUses === []) {
                    break;
                }
                if ($toolUses === [] || $forceText) {
                    break;
                }

                $blocks = [];
                foreach ($toolUses as $toolUse) {
                    $id = (string)($toolUse['id'] ?? '');
                    $outcome = $settled[$id] ?? null;
                    if (!$outcome instanceof ToolOutcome) {
                        continue;
                    }
                    $blocks[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $id,
                        'content' => $outcome->resultText,
                        'is_error' => $outcome->isError,
                    ];
                }
                $roundOutcomes = $settled;
                $history->append(['role' => 'user', 'content' => $blocks]);
                $settled = [];
                if ($config->closeOnPresentation && $history->roundClosesTurn($toolUses, $roundOutcomes, $executor)) {
                    $stopReason = 'end_turn';
                    break;
                }
            }
        } finally {
            $history->closeOpenToolUses($settled);
            $this->transcripts->append($binding->sessionId, $history->newMessages());
            $cleared = $history->compact($lastPromptTokens, $config->compactAboveTokens);
            if ($cleared > 0) {
                $this->transcripts->rewrite($binding->sessionId, $history->all());
            }
            $state->turnCounter++;
            $saved = $this->sessions->save($binding, $state);
            $persistedTurnNo = $state->turnCounter;
            if (!$saved) {
                $this->logger->warning(sprintf(
                    'session save failed session=%s turn=%d',
                    $this->digest($binding->sessionId),
                    $state->turnCounter
                ));
                $persistedTurnNo = $state->turnCounter - 1;
            }
            $this->turnLog->record([
                'session_id' => $binding->sessionId,
                'store_id' => $context->storeId,
                'turn_no' => $persistedTurnNo,
                'model_id' => $config->modelId,
                'rounds' => $rounds,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cache_creation_input_tokens' => $usage['cache_creation_input_tokens'],
                'cache_read_input_tokens' => $usage['cache_read_input_tokens'],
                'duration_ms' => (int)round((microtime(true) - $turnStart) * 1000),
                'stop_reason' => $stopReason,
            ]);
        }

        yield Event::turnComplete(
            $stopReason ?? 'end_turn',
            $usage,
            (int)round((microtime(true) - $turnStart) * 1000),
            $cleared,
            $binding->sessionId
        );
    }

    public function interrupt(Binding $binding): void
    {
        $this->logger->info(sprintf('turn interrupted session=%s', $this->digest($binding->sessionId)));
    }

    private function isWorthRetrying(\Throwable $exception): bool
    {
        if ($exception instanceof Transport) {
            return true;
        }
        return $exception instanceof ApiStreamError
            && in_array($exception->getApiType(), self::RETRYABLE_STREAM_ERROR_TYPES, true);
    }

    private function describe(\Throwable $exception): string
    {
        if ($exception instanceof ApiStreamError) {
            return $exception->getApiType() . ': ' . $exception->getMessage();
        }
        return $exception->getMessage();
    }

    private function accumulateUsage(array $totals, array $roundUsage): array
    {
        foreach ($totals as $key => $value) {
            $totals[$key] = $value + (int)($roundUsage[$key] ?? 0);
        }
        return $totals;
    }

    private function toolResultEvent(string $tool, string $id, ToolOutcome $outcome): Event
    {
        $refused = $outcome->isError || $outcome->blocked !== null;
        $keepText = $refused || mb_strlen($outcome->resultText) < self::SUMMARY_MAX_CHARS;
        $status = $outcome->blocked !== null ? 'blocked' : ($outcome->isError ? 'error' : 'ok');
        return Event::toolResult(
            $tool,
            $id,
            $keepText ? $outcome->resultText : 'ok',
            $outcome->isError,
            $status,
            $outcome->blocked,
            $keepText ? null : mb_substr($outcome->resultText, 0, self::EXCERPT_MAX_CHARS)
        );
    }

    private function digest(string $sessionId): string
    {
        return substr(sha1($sessionId), 0, 12);
    }
}
