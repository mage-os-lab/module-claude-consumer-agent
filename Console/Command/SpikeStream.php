<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Console\Command;

use Magento\Framework\Console\Cli;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator;
use MageOS\AiShoppingAssistant\Model\Client\Fixtures;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Session\Binding;
use MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository;
use MageOS\AiShoppingAssistant\Model\Eval\InMemorySessions;
use MageOS\AiShoppingAssistant\Model\Eval\InMemoryTranscripts;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not final: Magento generates an interceptor for console commands.
 *
 * The default mode runs one turn through the real orchestrator (SessionContext, an
 * empty SessionState, nothing persisted) against the real client and backend, so a
 * change to prompts, tools or gates can be watched end to end before an eval case is
 * written. --raw drops back to streaming MessagesClientInterface directly with a
 * hard-coded request, for verifying the SSE transport itself. buildOrchestrator() holds
 * this class's one object manager use.
 */
class SpikeStream extends Command
{
    private const OPTION_STORE = 'store';
    private const OPTION_MESSAGE = 'message';
    private const OPTION_PRODUCT = 'product';
    private const OPTION_RECORD = 'record';
    private const OPTION_RAW = 'raw';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface $client,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $config,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly \Magento\Quote\Api\CartManagementInterface $cartManagement,
        private readonly \Magento\Framework\App\State $appState,
        private readonly \Magento\Store\Model\App\Emulation $appEmulation
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('aiagent:spike:stream')
            ->setDescription('Stream one Claude Messages API turn for manual verification')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_OPTIONAL, 'Store code')
            ->addOption(self::OPTION_MESSAGE, null, InputOption::VALUE_OPTIONAL, 'User message', 'What time is it?')
            ->addOption(self::OPTION_PRODUCT, null, InputOption::VALUE_OPTIONAL, 'Product id for context')
            ->addOption(
                self::OPTION_RECORD,
                null,
                InputOption::VALUE_OPTIONAL,
                'Fixture name to record the raw SSE frames under'
            )
            ->addOption(
                self::OPTION_RAW,
                null,
                InputOption::VALUE_NONE,
                'Stream MessagesClientInterface directly with a hard-coded request instead of running the orchestrator'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
        }

        if ((bool)$input->getOption(self::OPTION_RAW)) {
            return $this->executeRaw($input, $output);
        }
        return $this->executeOrchestrated($input, $output);
    }

    private function executeOrchestrated(InputInterface $input, OutputInterface $output): int
    {
        $storeCode = $input->getOption(self::OPTION_STORE);
        $storeId = (int)$this->storeManager->getStore($storeCode)->getId();
        $message = (string)$input->getOption(self::OPTION_MESSAGE);
        $productId = $input->getOption(self::OPTION_PRODUCT);
        $record = $input->getOption(self::OPTION_RECORD);

        $client = $record !== null ? $this->recordingClient((string)$record) : $this->client;
        $page = $productId !== null
            ? PageContext::fromArray(['page_type' => 'product', 'product_id' => (string)$productId])
            : new PageContext();
        $context = new SessionContext(
            bin2hex(random_bytes(16)),
            null,
            (int)$this->cartManagement->createEmptyCart(),
            $storeId,
            $page,
            new \DateTimeImmutable()
        );
        $binding = new Binding($context->sessionId, null, new SessionState(), true, 0, $context);

        $orchestrator = $this->objectManager->create(Orchestrator::class, [
            'client' => $client,
            'backend' => $this->backend,
            'transcripts' => new TranscriptRepository(new InMemoryTranscripts(), $this->resourceConnection),
            'sessions' => new InMemorySessions(),
        ]);

        $this->appEmulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND);
        try {
            foreach ($orchestrator->streamTurn($binding, $message, $context) as $event) {
                if ($event->type === Event::TYPE_ERROR) {
                    $output->writeln('<error>error: ' . $event->data['message'] . '</error>');
                    return Cli::RETURN_FAILURE;
                }
                $this->printOrchestratorEvent($output, $event);
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        return Cli::RETURN_SUCCESS;
    }

    private function printOrchestratorEvent(OutputInterface $output, Event $event): void
    {
        switch ($event->type) {
            case Event::TYPE_TEXT_DELTA:
                $output->write((string)$event->data['text']);
                break;
            case Event::TYPE_TOOL_CALL:
                $output->writeln('');
                $output->writeln('[tool_call] ' . $event->data['tool'] . ' ' . $this->encodeJson($event->data['input']));
                break;
            case Event::TYPE_TOOL_RESULT:
                $output->writeln(
                    '[tool_result] ' . $event->data['tool']
                    . ' status=' . $event->data['status']
                    . ' ' . $event->data['summary']
                );
                break;
            case Event::TYPE_UI:
                $output->writeln('[ui] ' . $event->data['component']);
                break;
            case Event::TYPE_TURN_COMPLETE:
                $usage = $event->data['usage'];
                $output->writeln('');
                $output->writeln(
                    'usage input=' . ($usage['input_tokens'] ?? 0)
                    . ' output=' . ($usage['output_tokens'] ?? 0)
                    . ' cache_read=' . ($usage['cache_read_input_tokens'] ?? 0)
                    . ' cache_creation=' . ($usage['cache_creation_input_tokens'] ?? 0)
                    . ' elapsed_ms=' . $event->data['elapsed_ms']
                );
                break;
            default:
                break;
        }
    }

    private function recordingClient(string $name): \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface
    {
        $inner = $this->client;
        return new class ($inner, $name) implements \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface {
            private int $round = 0;

            public function __construct(
                private readonly \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface $inner,
                private readonly string $name
            ) {
            }

            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                $this->round++;
                $frames = '';
                foreach ($this->inner->stream($request, $onWaiting, $storeId) as $rawEvent) {
                    if ($rawEvent instanceof RawEvent) {
                        $json = json_encode($rawEvent->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $json = $json !== false ? $json : '{}';
                        $frames .= "event: {$rawEvent->type}\ndata: {$json}\n\n";
                    }
                    yield $rawEvent;
                }
                $dir = __DIR__ . '/../../Test/Eval/recordings';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $path = $dir . '/' . $this->name . '-turn' . $this->round . '.sse';
                file_put_contents($path, $frames);
            }
        };
    }

    private function executeRaw(InputInterface $input, OutputInterface $output): int
    {
        $storeCode = $input->getOption(self::OPTION_STORE);
        $storeId = (int)$this->storeManager->getStore($storeCode)->getId();
        $message = (string)$input->getOption(self::OPTION_MESSAGE);
        $productId = $input->getOption(self::OPTION_PRODUCT);
        $record = $input->getOption(self::OPTION_RECORD);

        $userText = $productId !== null ? $message . ' (product ' . $productId . ')' : $message;
        $request = $this->buildRequest($storeId, $userText);

        $rawFrames = '';
        $messageStartUsage = [];
        $messageDeltaUsage = [];

        try {
            foreach ($this->client->stream($request, null, $storeId) as $rawEvent) {
                if (!$rawEvent instanceof RawEvent) {
                    continue;
                }
                if ($record !== null) {
                    $rawFrames .= $this->encodeFrame($rawEvent);
                }
                $this->printEvent($output, $rawEvent);
                if ($rawEvent->type === 'message_start') {
                    $messageStartUsage = $rawEvent->data['message']['usage'] ?? [];
                }
                if ($rawEvent->type === 'message_delta') {
                    $messageDeltaUsage = $rawEvent->data['usage'] ?? [];
                }
                if ($rawEvent->type === 'error') {
                    $output->writeln('<error>error: ' . ($rawEvent->data['error']['message'] ?? 'unknown') . '</error>');
                    return Cli::RETURN_FAILURE;
                }
            }
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln('');
        $output->writeln(
            'usage input=' . ($messageStartUsage['input_tokens'] ?? 0)
            . ' output=' . ($messageDeltaUsage['output_tokens'] ?? ($messageStartUsage['output_tokens'] ?? 0))
            . ' cache_read=' . ($messageStartUsage['cache_read_input_tokens'] ?? 0)
            . ' cache_creation=' . ($messageStartUsage['cache_creation_input_tokens'] ?? 0)
        );

        if ($record !== null && $rawFrames !== '') {
            $this->recordFixture((string)$record, $rawFrames, $output);
        }

        return Cli::RETURN_SUCCESS;
    }

    private function buildRequest(int $storeId, string $userText): array
    {
        $agentConfig = $this->config->agent($storeId);
        return [
            'model' => $agentConfig->modelId,
            'max_tokens' => $agentConfig->maxTokens,
            'system' => [
                [
                    'type' => 'text',
                    'text' => 'You are a test assistant.',
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'tools' => [
                [
                    'name' => 'get_time',
                    'description' => 'Return the current time',
                    'input_schema' => ['type' => 'object', 'properties' => new \stdClass()],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'tool_choice' => ['type' => 'auto'],
            'messages' => [
                ['role' => 'user', 'content' => $userText],
            ],
            'thinking' => ['type' => 'adaptive'],
            'output_config' => ['effort' => 'low'],
        ];
    }

    private function printEvent(OutputInterface $output, RawEvent $rawEvent): void
    {
        if ($rawEvent->type === 'content_block_delta' && ($rawEvent->data['delta']['type'] ?? null) === 'text_delta') {
            $output->write((string)($rawEvent->data['delta']['text'] ?? ''));
            return;
        }
        $output->writeln('[' . $rawEvent->type . ']');
    }

    private function encodeFrame(RawEvent $rawEvent): string
    {
        $json = json_encode($rawEvent->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json !== false ? $json : '{}';
        return "event: {$rawEvent->type}\ndata: {$json}\n\n";
    }

    private function recordFixture(string $name, string $rawFrames, OutputInterface $output): void
    {
        $path = dirname(Fixtures::path($name)) . '/' . $name . '-round1.sse';
        file_put_contents($path, $rawFrames);
        $output->writeln('recorded ' . $path);
    }

    private function encodeJson(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '{}';
    }
}
