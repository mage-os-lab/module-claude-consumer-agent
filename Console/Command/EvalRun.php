<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Console\Command;

use Magento\Framework\Console\Cli;
use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;
use MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface;
use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\CartWrite;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\Rules;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Runner as PresentationRunner;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\CoreToolProvider;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\AddToCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetFulfillmentOptions;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrders;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetOrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetPreferences;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetProductDetails;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\LoadSkill;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\MemoryOff;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\RemoveFromCart;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchCategories;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchPolicies;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchProducts;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\UpdateCartItem;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry as ToolRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator;
use MageOS\AiShoppingAssistant\Model\Client\FakeClient;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use MageOS\AiShoppingAssistant\Model\Client\Sleeper;
use MageOS\AiShoppingAssistant\Model\Client\SseLineReader;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Session\Binding;
use MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository;
use MageOS\AiShoppingAssistant\Model\Eval\FakeBackend;
use MageOS\AiShoppingAssistant\Model\Eval\FakeExecutorFactory;
use MageOS\AiShoppingAssistant\Model\Eval\FakeScopeConfig;
use MageOS\AiShoppingAssistant\Model\Eval\InMemorySessions;
use MageOS\AiShoppingAssistant\Model\Eval\InMemorySkuMatcher;
use MageOS\AiShoppingAssistant\Model\Eval\InMemoryTranscripts;
use MageOS\AiShoppingAssistant\Model\Eval\InMemoryTurnLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not final: Magento generates an interceptor for console commands.
 *
 * Replays scripted turns through a hand-wired Orchestrator so that, in fixture mode, a
 * tool call never reaches the real storefront: every constructor argument of
 * Orchestrator is supplied explicitly to the one ObjectManagerInterface::create() call
 * in buildOrchestrator(), which is the only object manager use in this class.
 */
class EvalRun extends Command
{
    private const OPTION_CASES = 'cases';
    private const OPTION_FILTER = 'filter';
    private const OPTION_LIVE = 'live';
    private const OPTION_STORE = 'store';
    private const OPTION_JSON = 'json';

    private const DEFAULT_CASES_DIR = 'Test/Eval/cases';
    private const RECORDINGS_DIR = 'Test/Eval/recordings';
    private const HARD_FAIL_PRIORITIES = ['critical', 'high'];

    public function __construct(
        private readonly \Magento\Framework\ObjectManagerInterface $objectManager,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Eval\Toolkit $toolkit,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface $skuMatcher,
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly \Magento\Framework\App\ResourceConnection $resourceConnection,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface $liveClient,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $liveBackend,
        private readonly \Magento\Quote\Api\CartManagementInterface $cartManagement,
        private readonly \Magento\Framework\App\State $appState,
        private readonly \Magento\Store\Model\App\Emulation $appEmulation,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('aiagent:eval:run')
            ->setDescription('Replays scripted eval cases through the orchestrator and grades the outcome')
            ->addOption(self::OPTION_CASES, null, InputOption::VALUE_OPTIONAL, 'Case directory', self::DEFAULT_CASES_DIR)
            ->addOption(self::OPTION_FILTER, null, InputOption::VALUE_OPTIONAL, 'Glob on the case id')
            ->addOption(self::OPTION_LIVE, null, InputOption::VALUE_NONE, 'Run against the real client and backend')
            ->addOption(self::OPTION_STORE, null, InputOption::VALUE_OPTIONAL, 'Store code')
            ->addOption(self::OPTION_JSON, null, InputOption::VALUE_NONE, 'Print results as JSON');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeCode = $input->getOption(self::OPTION_STORE);
        $storeId = (int)$this->storeManager->getStore($storeCode)->getId();
        $live = (bool)$input->getOption(self::OPTION_LIVE);
        $filter = $input->getOption(self::OPTION_FILTER);
        $asJson = (bool)$input->getOption(self::OPTION_JSON);
        $casesDir = $this->resolvePath((string)$input->getOption(self::OPTION_CASES));

        $caseFiles = glob($casesDir . '/*.json');
        $caseFiles = $caseFiles !== false ? $caseFiles : [];
        sort($caseFiles);

        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
        }

        $results = [];
        $hardFailure = false;
        $this->appEmulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND);
        try {
            foreach ($caseFiles as $caseFile) {
                $case = json_decode((string)file_get_contents($caseFile), true);
                if (!is_array($case)) {
                    continue;
                }
                $id = (string)($case['id'] ?? basename($caseFile, '.json'));
                if ($filter !== null && !fnmatch((string)$filter, $id)) {
                    continue;
                }
                $result = $this->runCase($case, $id, $storeId, $live);
                $results[] = $result;
                if (!$result['pass'] && in_array($result['priority'], self::HARD_FAIL_PRIORITIES, true)) {
                    $hardFailure = true;
                }
            }
        } finally {
            $this->appEmulation->stopEnvironmentEmulation();
        }

        if ($asJson) {
            $output->writeln((string)json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->printTable($output, $results);
        }

        return $hardFailure ? Cli::RETURN_FAILURE : Cli::RETURN_SUCCESS;
    }

    private function runCase(array $case, string $id, int $storeId, bool $live): array
    {
        $priority = (string)($case['priority'] ?? 'low');
        $state = is_array($case['state'] ?? null) ? $case['state'] : [];
        $seenProducts = is_array($state['seen_products'] ?? null) ? $state['seen_products'] : [];
        $catalogSource = is_array($state['catalog'] ?? null) ? $state['catalog'] : [];
        $catalog = [];
        foreach (array_merge($catalogSource, $seenProducts) as $record) {
            if (is_array($record) && isset($record['product_id'])) {
                $catalog[(string)$record['product_id']] = $record;
            }
        }

        $quoteId = $live ? (int)$this->cartManagement->createEmptyCart() : 900000 + crc32($id);
        $context = new SessionContext(
            bin2hex(random_bytes(16)),
            isset($state['customer_id']) ? (int)$state['customer_id'] : null,
            $quoteId,
            $storeId,
            PageContext::fromArray(is_array($state['page'] ?? null) ? $state['page'] : []),
            new \DateTimeImmutable()
        );
        $sessionState = new SessionState(
            lastPage: is_array($state['last_page'] ?? null) ? $state['last_page'] : []
        );
        $sessionState->rememberProducts($seenProducts);
        $binding = new Binding($context->sessionId, null, $sessionState, true, 0, $context);

        $backend = $live ? $this->liveBackend : new FakeBackend(
            $catalog,
            is_array($state['preferences'] ?? null) ? $state['preferences'] : [],
            is_array($state['orders'] ?? null) ? $state['orders'] : [],
            is_array($state['policies'] ?? null) ? $state['policies'] : [],
            is_array($state['fulfillment'] ?? null) ? $state['fulfillment'] : [],
            is_array($state['categories'] ?? null) ? $state['categories'] : []
        );
        $client = $live ? $this->liveClient : new FakeClient();
        $skuMatcher = $live ? $this->skuMatcher : new InMemorySkuMatcher(array_map('strval', array_keys($catalog)));

        if ($live) {
            foreach ((is_array($state['cart'] ?? null) ? $state['cart'] : []) as $line) {
                $backend->addToCart($context, (string)($line['product_id'] ?? ''), (int)($line['quantity'] ?? 1));
            }
        }

        $storeConfig = $this->storeConfigForCase($case);
        $orchestrator = $this->buildOrchestrator($backend, $client, $storeConfig, $skuMatcher);

        $toolCalls = [];
        $uiComponents = [];
        $productsPayloads = [];
        $replyText = '';
        $turns = is_array($case['turns'] ?? null) ? array_values($case['turns']) : [];
        foreach ($turns as $index => $message) {
            $turnNumber = $index + 1;
            if (!$live && $client instanceof FakeClient) {
                foreach ($this->roundsForTurn($id, $turnNumber, $case) as $rawEvents) {
                    $client->addRound($rawEvents);
                }
            }
            $events = iterator_to_array($orchestrator->streamTurn($binding, (string)$message, $context), false);
            foreach ($events as $event) {
                if ($event->type === Event::TYPE_TOOL_CALL) {
                    $toolCalls[] = (string)$event->data['tool'];
                }
                if ($event->type === Event::TYPE_UI) {
                    $uiComponents[] = (string)$event->data['component'];
                    if ($event->data['component'] === 'products') {
                        $productsPayloads[] = is_array($event->data['payload'] ?? null) ? $event->data['payload'] : [];
                    }
                }
                if ($event->type === Event::TYPE_TEXT_DELTA) {
                    $replyText .= (string)$event->data['text'];
                }
            }
        }

        $cart = $backend->getCart($context);
        $expected = is_array($case['expected'] ?? null) ? $case['expected'] : [];
        $grading = $this->gradeCase($expected, $toolCalls, $uiComponents, $productsPayloads, $replyText, $cart);

        return [
            'id' => $id,
            'priority' => $priority,
            'difficulty' => (string)($case['difficulty'] ?? ''),
            'pass' => $grading['pass'],
            'failed' => $grading['failed'],
            'manual' => $grading['manual'],
        ];
    }

    private function storeConfigForCase(array $case): \MageOS\AiShoppingAssistant\Model\Config\StoreConfig
    {
        $overrides = is_array($case['config'] ?? null) ? $case['config'] : [];
        if ($overrides === []) {
            return $this->toolkit->storeConfig();
        }

        $values = [];
        if (array_key_exists('store_facts', $overrides)) {
            $values['ai_integration/aiagent/content/store_facts'] = (string)json_encode($overrides['store_facts']);
        }
        if (array_key_exists('include_core_facts', $overrides)) {
            $values['ai_integration/aiagent/content/include_core_facts'] = $overrides['include_core_facts'] ? '1' : '0';
        }

        return new \MageOS\AiShoppingAssistant\Model\Config\StoreConfig(
            new FakeScopeConfig($values),
            $this->storeManager
        );
    }

    private function buildOrchestrator(
        StorefrontBackendInterface $backend,
        MessagesClientInterface $client,
        \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        SkuMatcherInterface $skuMatcher
    ): Orchestrator {
        $cartWrite = new CartWrite(
            $backend,
            $this->lockManager,
            $this->toolkit->options(),
            $this->toolkit->provenance(),
            $this->toolkit->serializer(),
            $this->logger
        );
        $handlers = [
            'load_skill' => new LoadSkill($this->toolkit->skillRegistry()),
            'search_products' => new SearchProducts($backend, $this->toolkit->serializer()),
            'search_categories' => new SearchCategories($backend, $this->toolkit->serializer()),
            'get_product_details' => new GetProductDetails(
                $backend,
                $this->toolkit->serializer(),
                $this->toolkit->fence()
            ),
            'get_cart' => new GetCart($backend, $this->toolkit->serializer(), $this->toolkit->fence()),
            'add_to_cart' => new AddToCart($cartWrite),
            'update_cart_item' => new UpdateCartItem($cartWrite),
            'remove_from_cart' => new RemoveFromCart($cartWrite),
            'get_preferences' => new GetPreferences($backend, $this->toolkit->fence()),
            'get_orders' => new GetOrders($backend, $this->toolkit->serializer(), $this->toolkit->fence()),
            'get_order_status' => new GetOrderStatus($backend, $this->toolkit->serializer(), $this->toolkit->fence()),
            'search_policies' => new SearchPolicies($backend, $this->toolkit->serializer(), $this->toolkit->fence()),
            'get_fulfillment_options' => new GetFulfillmentOptions(
                $backend,
                $this->toolkit->serializer(),
                $this->toolkit->fence()
            ),
            'memory_off' => new MemoryOff(),
        ];

        $coreToolProvider = new CoreToolProvider(
            $handlers['load_skill'],
            $handlers['search_products'],
            $handlers['search_categories'],
            $handlers['get_product_details'],
            $handlers['get_cart'],
            $handlers['add_to_cart'],
            $handlers['update_cart_item'],
            $handlers['remove_from_cart'],
            $handlers['get_preferences'],
            $handlers['get_orders'],
            $handlers['get_order_status'],
            $handlers['search_policies'],
            $handlers['get_fulfillment_options'],
            $handlers['memory_off'],
            $this->toolkit->skillRegistry(),
            $this->toolkit->presentationRegistry()
        );
        $toolRegistry = new ToolRegistry([$coreToolProvider], $storeConfig);
        $presentationRunner = new PresentationRunner(
            $this->toolkit->presentationRegistry(),
            $this->toolkit->validator(),
            $backend,
            $storeConfig
        );
        $executorFactory = new FakeExecutorFactory(
            $toolRegistry,
            $presentationRunner,
            $this->toolkit->validator(),
            $this->toolkit->provenance(),
            $this->toolkit->sanitizer(),
            $this->logger
        );
        $rules = new Rules($this->toolkit->lexicon(), $this->toolkit->skuCandidates(), $skuMatcher);

        return $this->objectManager->create(Orchestrator::class, [
            'client' => $client,
            'backend' => $backend,
            'staticSystem' => $this->toolkit->staticSystem(),
            'dynamicContext' => $this->toolkit->dynamicContext(),
            'assembly' => $this->toolkit->assembly(),
            'pageNote' => new PageNote($this->toolkit->sanitizer()),
            'toolRegistry' => $toolRegistry,
            'executorFactory' => $executorFactory,
            'rules' => $rules,
            'transcripts' => new TranscriptRepository(new InMemoryTranscripts(), $this->resourceConnection),
            'sessions' => new InMemorySessions(),
            'storeConfig' => $storeConfig,
            'logger' => $this->logger,
            'streamedRoundFactory' => $this->toolkit->streamedRoundFactory(),
            'turnLog' => new InMemoryTurnLog(),
            'sleeper' => new Sleeper(),
        ]);
    }

    private function roundsForTurn(string $caseId, int $turnNumber, array $case): array
    {
        $recordingFile = $this->resolvePath(self::RECORDINGS_DIR) . '/' . $caseId . '-turn' . $turnNumber . '.sse';
        if (is_file($recordingFile)) {
            return $this->splitRecordingIntoRounds((string)file_get_contents($recordingFile));
        }
        $definitions = $case['rounds'][$turnNumber - 1] ?? [];
        $rounds = [];
        foreach ((is_array($definitions) ? $definitions : []) as $index => $round) {
            $rounds[] = $this->buildRawEventsForRound($caseId, $turnNumber, $index, is_array($round) ? $round : []);
        }
        return $rounds;
    }

    private function buildRawEventsForRound(string $caseId, int $turnNumber, int $index, array $round): array
    {
        $text = isset($round['text']) ? (string)$round['text'] : '';
        $tools = is_array($round['tools'] ?? null) ? $round['tools'] : [];
        if ($tools === []) {
            return FakeClient::textRound($text);
        }
        $toolUses = [];
        foreach ($tools as $position => $tool) {
            $toolUses[] = [
                'id' => sprintf('toolu_%s_%d_%d_%d', $caseId, $turnNumber, $index, $position),
                'name' => (string)($tool['name'] ?? ''),
                'input' => is_array($tool['input'] ?? null) ? $tool['input'] : [],
            ];
        }
        return FakeClient::toolRound($toolUses, $text);
    }

    private function splitRecordingIntoRounds(string $content): array
    {
        $reader = new SseLineReader();
        $events = iterator_to_array($reader->read([$content]), false);
        $rounds = [];
        $current = [];
        foreach ($events as $event) {
            if (!$event instanceof RawEvent) {
                continue;
            }
            if ($event->type === 'message_start' && $current !== []) {
                $rounds[] = $current;
                $current = [];
            }
            $current[] = $event;
        }
        if ($current !== []) {
            $rounds[] = $current;
        }
        return $rounds;
    }

    private function gradeCase(
        array $expected,
        array $toolCalls,
        array $uiComponents,
        array $productsPayloads,
        string $replyText,
        CartInterface $cart
    ): array {
        $failed = [];
        $manual = [];
        foreach ($expected as $key => $value) {
            if ($key === 'rubric') {
                $manual[] = $key;
                continue;
            }
            if (!$this->gradeKey((string)$key, $value, $toolCalls, $uiComponents, $productsPayloads, $replyText, $cart)) {
                $failed[] = (string)$key;
            }
        }
        return ['failed' => $failed, 'manual' => $manual, 'pass' => $failed === []];
    }

    private function gradeKey(
        string $key,
        mixed $value,
        array $toolCalls,
        array $uiComponents,
        array $productsPayloads,
        string $replyText,
        CartInterface $cart
    ): bool {
        return match ($key) {
            'calls_tool' => $this->allPresent((array)$value, $toolCalls),
            'calls_one_of' => $this->anyPresent((array)$value, $toolCalls),
            'never_calls' => $this->nonePresent((array)$value, $toolCalls),
            'first_tool' => ($toolCalls[0] ?? null) === $value,
            'ui_components' => $this->allPresent((array)$value, $uiComponents),
            'cart_contains' => $this->allPresent((array)$value, $this->cartProductIds($cart)),
            'cart_not_contains' => $this->nonePresent((array)$value, $this->cartProductIds($cart)),
            'cart_item_count' => $cart->getItemCount() === (int)$value,
            'reply_includes' => $this->allSubstrings((array)$value, $replyText),
            'reply_omits' => $this->noneSubstrings((array)$value, $replyText),
            'max_tool_calls' => count($toolCalls) <= (int)$value,
            'products_option_values' => $this->productsMatchOptionValues((array)$value, $productsPayloads),
            'products_min_distinct_brands' => $this->productsMinDistinctBrands((int)$value, $productsPayloads),
            'products_max_brand_share' => $this->productsMaxBrandShare((int)$value, $productsPayloads),
            default => true,
        };
    }

    private function cartProductIds(CartInterface $cart): array
    {
        return array_map(
            static fn ($item): string => $item->getProductId(),
            $cart->getItems()
        );
    }

    private function allPresent(array $expected, array $actual): bool
    {
        foreach ($expected as $item) {
            if (!in_array($item, $actual, true)) {
                return false;
            }
        }
        return true;
    }

    private function anyPresent(array $expected, array $actual): bool
    {
        if ($expected === []) {
            return true;
        }
        foreach ($expected as $item) {
            if (in_array($item, $actual, true)) {
                return true;
            }
        }
        return false;
    }

    private function productsMatchOptionValues(array $wanted, array $productsPayloads): bool
    {
        if ($wanted === [] || $productsPayloads === []) {
            return false;
        }
        $checked = 0;
        foreach ($productsPayloads as $payload) {
            foreach ((is_array($payload['items'] ?? null) ? $payload['items'] : []) as $item) {
                $optionValues = is_array($item['option_values'] ?? null) ? $item['option_values'] : [];
                if (!Products::matchesOptionValues($optionValues, $wanted)) {
                    return false;
                }
                $checked++;
            }
        }
        return $checked > 0;
    }

    private function productsMinDistinctBrands(int $minimum, array $productsPayloads): bool
    {
        return count(array_unique($this->pickedBrands($productsPayloads))) >= $minimum;
    }

    private function productsMaxBrandShare(int $maximum, array $productsPayloads): bool
    {
        foreach (array_count_values($this->pickedBrands($productsPayloads)) as $count) {
            if ($count > $maximum) {
                return false;
            }
        }
        return true;
    }

    private function pickedBrands(array $productsPayloads): array
    {
        $brands = [];
        foreach ($productsPayloads as $payload) {
            foreach ((is_array($payload['items'] ?? null) ? $payload['items'] : []) as $item) {
                $product = is_array($item['product'] ?? null) ? $item['product'] : [];
                $brand = isset($product['brand']) ? trim((string)$product['brand']) : '';
                if ($brand !== '') {
                    $brands[] = $brand;
                }
            }
        }
        return $brands;
    }

    private function nonePresent(array $forbidden, array $actual): bool
    {
        foreach ($forbidden as $item) {
            if (in_array($item, $actual, true)) {
                return false;
            }
        }
        return true;
    }

    private function allSubstrings(array $needles, string $haystack): bool
    {
        $lower = mb_strtolower($haystack);
        foreach ($needles as $needle) {
            if (!str_contains($lower, mb_strtolower((string)$needle))) {
                return false;
            }
        }
        return true;
    }

    private function noneSubstrings(array $needles, string $haystack): bool
    {
        $lower = mb_strtolower($haystack);
        foreach ($needles as $needle) {
            if (str_contains($lower, mb_strtolower((string)$needle))) {
                return false;
            }
        }
        return true;
    }

    private function printTable(OutputInterface $output, array $results): void
    {
        $table = new Table($output);
        $table->setHeaders(['id', 'priority', 'result', 'failed keys']);
        foreach ($results as $result) {
            $notes = $result['failed'];
            if ($result['manual'] !== []) {
                $notes[] = 'rubric: judge: manual';
            }
            $status = 'FAIL';
            if ($result['pass']) {
                $status = $result['manual'] !== [] ? 'PASS (manual)' : 'PASS';
            }
            $table->addRow([
                $result['id'],
                $result['priority'],
                $status,
                implode(', ', $notes),
            ]);
        }
        $table->render();
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        return dirname(__DIR__, 2) . '/' . rtrim($path, '/');
    }
}
