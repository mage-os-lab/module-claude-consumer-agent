<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Executor;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\NotOffered;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Runner;
use MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class ExecutorTestFakeHandler implements HandlerInterface
{
    public function __construct(
        private $callback
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        return ($this->callback)($input, $context, $state, $config);
    }
}

final class ExecutorTestFakeToolProvider implements ToolProviderInterface
{
    public function __construct(
        private readonly array $definitions
    ) {
    }

    public function getTools(AgentConfig $config): array
    {
        return $this->definitions;
    }
}

final class ExecutorTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('s-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function storeConfig(): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function buildRunner(?StorefrontBackendInterface $backend = null): Runner
    {
        $serializer = new Serializer(new Fence(new Sanitizer()));
        $registry = new PresentationRegistry(
            new Products($this->createMock(LoggerInterface::class), new Sanitizer()),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions(new Sanitizer())
        );
        return new Runner(
            $registry,
            new Validator(),
            $backend ?? $this->createMock(StorefrontBackendInterface::class),
            $this->storeConfig()
        );
    }

    private function buildExecutor(
        Definition $definition,
        ?SessionState $state = null,
        ?LoggerInterface $logger = null,
        ?Runner $runner = null
    ): Executor {
        $registry = new Registry([new ExecutorTestFakeToolProvider([$definition])], $this->storeConfig());
        return new Executor(
            $registry,
            $runner ?? $this->buildRunner(),
            new Validator(),
            new Provenance(),
            new Sanitizer(),
            $logger ?? $this->createMock(LoggerInterface::class),
            $this->context(),
            $state ?? new SessionState(),
            new AgentConfig()
        );
    }

    private function definition(
        string $name,
        array $inputSchema = ['type' => 'object', 'properties' => []],
        string $kind = 'read',
        ?HandlerInterface $handler = null,
        array $productIdArguments = [],
        bool $remembersProducts = false,
        bool $takesStatus = false
    ): Definition {
        return new Definition($name, 'a tool', $inputSchema, $kind, $handler, $productIdArguments, $remembersProducts, $takesStatus, 0);
    }

    public function testUnknownToolIsASoftError(): void
    {
        $registry = new Registry([new ExecutorTestFakeToolProvider([])], $this->storeConfig());
        $executor = new Executor(
            $registry,
            $this->buildRunner(),
            new Validator(),
            new Provenance(),
            new Sanitizer(),
            $this->createMock(LoggerInterface::class),
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
        $result = $executor->dispatch('teleport_products', []);
        $this->assertTrue($result->isError);
        $this->assertSame('Unknown tool: teleport_products', $result->resultText);
    }

    public function testStatusIsSplitAndTheLabelIsSanitizedAndCapped(): void
    {
        $handler = new ExecutorTestFakeHandler(
            static fn (array $input): ToolOutcome => ToolOutcome::ok('ok:' . json_encode($input))
        );
        $definition = $this->definition(
            'search_products',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            'read',
            $handler,
            [],
            false,
            true
        );
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('search_products', [
            'query' => 'tent',
            'status' => "Checking\u{200b} the catalog\x07 now " . str_repeat('x', 100),
        ]);
        $this->assertFalse($result->isError);
        $this->assertNotNull($result->label);
        $this->assertLessThanOrEqual(60, mb_strlen($result->label));
        $this->assertStringNotContainsString("\u{200b}", $result->label);
        $this->assertSame(['query' => 'tent'], $result->argumentsShown);
        $this->assertStringNotContainsString('"status"', $result->resultText);
    }

    public function testInvalidArgumentsProduceTheAdjustAndRetryLadder(): void
    {
        $handler = new ExecutorTestFakeHandler(static fn (): ToolOutcome => ToolOutcome::ok('unused'));
        $definition = $this->definition(
            'search_products',
            [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string']],
                'required' => ['query'],
            ],
            'read',
            $handler
        );
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('search_products', []);
        $this->assertTrue($result->isError);
        $this->assertStringStartsWith(
            'search_products arguments were invalid: query is required',
            $result->resultText
        );
        $this->assertStringEndsWith('Adjust and call it again.', $result->resultText);
    }

    public function testUnavailableExceptionIsRelayedWithTheSoldOutWording(): void
    {
        $handler = new ExecutorTestFakeHandler(static function (): never {
            throw new Unavailable('AR-1602 is out of stock');
        });
        $definition = $this->definition('add_to_cart', ['type' => 'object', 'properties' => []], 'write', $handler);
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('add_to_cart', []);
        $this->assertTrue($result->isError);
        $this->assertSame(
            'Nothing was added: AR-1602 is out of stock. Tell the customer, offer what the message names '
            . 'as available, and add that only once they choose it.',
            $result->resultText
        );
    }

    public function testNotOfferedExceptionIsRelayedAsSuchNotAsAnOutage(): void
    {
        $handler = new ExecutorTestFakeHandler(static function (): never {
            throw new NotOffered('Delivery for items another seller ships');
        });
        $definition = $this->definition(
            'get_fulfillment_options',
            ['type' => 'object', 'properties' => []],
            'read',
            $handler
        );
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('get_fulfillment_options', []);
        $this->assertTrue($result->isError);
        $this->assertSame(
            'Delivery for items another seller ships is not something this store offers; say so plainly.',
            $result->resultText
        );
        $this->assertStringNotContainsString('unavailable', $result->resultText);
    }

    public function testSignInRequiredExceptionIsRelayed(): void
    {
        $handler = new ExecutorTestFakeHandler(static function (): never {
            throw new SignInRequired('guest');
        });
        $definition = $this->definition('get_orders', ['type' => 'object', 'properties' => []], 'read', $handler);
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('get_orders', []);
        $this->assertTrue($result->isError);
        $this->assertSame(
            'This needs a signed-in customer. Ask the customer to sign in and try again.',
            $result->resultText
        );
    }

    public function testAGenericFailureIsLoggedAndReportedAsTemporarilyUnavailable(): void
    {
        $handler = new ExecutorTestFakeHandler(static function (): never {
            throw new \RuntimeException('backend down');
        });
        $definition = $this->definition('search_products', ['type' => 'object', 'properties' => []], 'read', $handler);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('tool failed', $this->callback(static function (array $context): bool {
                return $context['tool'] === 'search_products' && $context['exception'] instanceof \RuntimeException;
            }));
        $executor = $this->buildExecutor($definition, null, $logger);
        $result = $executor->dispatch('search_products', []);
        $this->assertTrue($result->isError);
        $this->assertSame(
            'search_products is temporarily unavailable. Work with what you already have or let the customer know.',
            $result->resultText
        );
    }

    public function testProvenanceGateAppliesOnANestedPicksPath(): void
    {
        $handler = new ExecutorTestFakeHandler(static fn (): ToolOutcome => ToolOutcome::ok('unused'));
        $definition = $this->definition(
            'present_products_tool',
            ['type' => 'object', 'properties' => []],
            'write',
            $handler,
            ['picks[].product_id']
        );
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('present_products_tool', ['picks' => [['product_id' => 'ghost-1']]]);
        $this->assertSame(Provenance::NAME, $result->blocked);
        $this->assertStringContainsString('ghost-1', $result->resultText);
    }

    public function testProvenanceGateAppliesOnAProductIdsListPath(): void
    {
        $handler = new ExecutorTestFakeHandler(static fn (): ToolOutcome => ToolOutcome::ok('unused'));
        $definition = $this->definition(
            'get_fulfillment_options',
            ['type' => 'object', 'properties' => []],
            'read',
            $handler,
            ['product_ids[]']
        );
        $executor = $this->buildExecutor($definition);
        $result = $executor->dispatch('get_fulfillment_options', ['product_ids' => ['ghost-1']]);
        $this->assertSame(Provenance::NAME, $result->blocked);
    }

    public function testRemembersProductsFeedsSessionState(): void
    {
        $products = [['product_id' => 'p-1', 'title' => 'Thing', 'price' => 1.0]];
        $handler = new ExecutorTestFakeHandler(
            static fn (): ToolOutcome => ToolOutcome::ok('found', [], $products)
        );
        $definition = $this->definition(
            'search_products',
            ['type' => 'object', 'properties' => []],
            'read',
            $handler,
            [],
            true
        );
        $state = new SessionState();
        $executor = $this->buildExecutor($definition, $state);
        $executor->dispatch('search_products', []);
        $this->assertTrue($state->hasSeen('p-1'));
    }

    public function testPresentationToolsAreRoutedToTheRunnerAndBypassTheSchemaValidator(): void
    {
        $definition = $this->definition(
            'present_products',
            PresentationRegistry::presentProductsSchema(),
            'presentation'
        );
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'p-1', 'title' => 'Mug', 'price' => 9.0]]);
        $executor = $this->buildExecutor($definition, $state);
        $result = $executor->dispatch('present_products', ['picks' => [['product_id' => 'p-1']]]);
        $this->assertFalse($result->isError);
        $this->assertSame('Displayed to the customer.', $result->resultText);
        $this->assertSame('ui', $result->events[0]->type);
        $this->assertSame('products', $result->events[0]->data['component']);
        $this->assertTrue($executor->endsClean('present_products', $result));
    }

    public function testDispatchPassesTheToolUseIdAsTheStreamIdToThePresentationRunner(): void
    {
        $definition = $this->definition(
            'present_products',
            PresentationRegistry::presentProductsSchema(),
            'presentation'
        );
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'p-1', 'title' => 'Mug', 'price' => 9.0]]);
        $executor = $this->buildExecutor($definition, $state);
        $result = $executor->dispatch('present_products', ['picks' => [['product_id' => 'p-1']]], 'tu_123');
        $this->assertFalse($result->isError);
        $this->assertSame('tu_123', $result->events[0]->data['stream_id']);
    }

    public function testEndsCleanIsFalseWhenTheResultIsAnError(): void
    {
        $definition = $this->definition('present_products', PresentationRegistry::presentProductsSchema(), 'presentation');
        $executor = $this->buildExecutor($definition);
        $this->assertFalse($executor->endsClean('present_products', ToolOutcome::error('nope')));
    }

    public function testEndsCleanIsFalseForANonPresentationTool(): void
    {
        $definition = $this->definition('search_products');
        $executor = $this->buildExecutor($definition);
        $this->assertFalse($executor->endsClean('search_products', ToolOutcome::ok('Displayed to the customer.')));
    }
}
