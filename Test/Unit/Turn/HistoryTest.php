<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Executor;
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
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry as ToolRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\History;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase
{
    public function testIsFirstTurnTrueWhenNoMessageExistedBeforeThisTurn(): void
    {
        $history = new History([], 0);
        $history->append(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]);
        $this->assertTrue($history->isFirstTurn());
    }

    public function testIsFirstTurnFalseWhenMessagesPreExisted(): void
    {
        $loaded = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'earlier']]]];
        $history = new History($loaded, count($loaded));
        $this->assertFalse($history->isFirstTurn());
    }

    public function testNewMessagesReturnsOnlyWhatWasAppendedThisTurn(): void
    {
        $loaded = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'earlier']]]];
        $history = new History($loaded, count($loaded));
        $history->append(['role' => 'user', 'content' => [['type' => 'text', 'text' => 'now']]]);
        $history->append(['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'reply']]]);

        $this->assertCount(2, $history->newMessages());
        $this->assertSame('now', $history->newMessages()[0]['content'][0]['text']);
        $this->assertSame('reply', $history->newMessages()[1]['content'][0]['text']);
        $this->assertCount(3, $history->all());
    }

    public function testCloseOpenToolUsesAppendsInterruptedResultForEveryUnsettledCall(): void
    {
        $history = new History([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'find a lamp']]],
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'search_products', 'input' => []],
                    ['type' => 'tool_use', 'id' => 't2', 'name' => 'get_cart', 'input' => []],
                ],
            ],
        ], 2);

        $history->closeOpenToolUses([]);

        $last = $history->all()[array_key_last($history->all())];
        $this->assertSame('user', $last['role']);
        $this->assertCount(2, $last['content']);
        foreach ($last['content'] as $block) {
            $this->assertSame('tool_result', $block['type']);
            $this->assertSame('Interrupted before this tool finished.', $block['content']);
            $this->assertTrue($block['is_error']);
        }
        $this->assertSame('t1', $last['content'][0]['tool_use_id']);
        $this->assertSame('t2', $last['content'][1]['tool_use_id']);
    }

    public function testCloseOpenToolUsesUsesTheRealOutcomeWhenOneCallSettled(): void
    {
        $history = new History([
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'search_products', 'input' => []],
                    ['type' => 'tool_use', 'id' => 't2', 'name' => 'get_cart', 'input' => []],
                ],
            ],
        ], 1);

        $history->closeOpenToolUses(['t1' => ToolOutcome::ok('found two matches')]);

        $last = $history->all()[array_key_last($history->all())];
        $this->assertSame('found two matches', $last['content'][0]['content']);
        $this->assertFalse($last['content'][0]['is_error']);
        $this->assertSame('Interrupted before this tool finished.', $last['content'][1]['content']);
        $this->assertTrue($last['content'][1]['is_error']);
    }

    public function testCloseOpenToolUsesIsNoOpWhenLastMessageIsAUser(): void
    {
        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ];
        $history = new History($messages, 1);
        $history->closeOpenToolUses([]);
        $this->assertCount(1, $history->all());
    }

    public function testCloseOpenToolUsesIsNoOpWhenLastMessageIsATwoTextBlockUserMessage(): void
    {
        $messages = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => '[Page: the customer is now on the cart page.]'],
                    ['type' => 'text', 'text' => 'is this one better?'],
                ],
            ],
        ];
        $history = new History($messages, 1);
        $history->closeOpenToolUses([]);
        $this->assertCount(1, $history->all());
        $this->assertCount(2, $history->all()[0]['content']);
        $this->assertSame('is this one better?', $history->all()[0]['content'][1]['text']);
    }

    public function testCloseOpenToolUsesIsNoOpWhenAssistantMessageHasNoToolUse(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ];
        $history = new History($messages, 1);
        $history->closeOpenToolUses([]);
        $this->assertCount(1, $history->all());
    }

    public function testCompactReturnsZeroBelowThreshold(): void
    {
        $history = new History($this->conversationWithRounds(6), 0);
        $this->assertSame(0, $history->compact(99000, 100000));
    }

    public function testCompactReturnsZeroWhenDisabled(): void
    {
        $history = new History($this->conversationWithRounds(6), 0);
        $this->assertSame(0, $history->compact(500000, 0));
    }

    public function testCompactClearsOldestToolResultsButProtectsTheLastTwoRounds(): void
    {
        $history = new History($this->conversationWithRounds(6), 0);

        $cleared = $history->compact(100000, 100000);

        $this->assertGreaterThan(0, $cleared);
        $bodies = $this->toolResultBodies($history->all());
        $lastTwo = array_slice($bodies, -2);
        foreach ($lastTwo as $body) {
            $this->assertNotSame('[cleared]', $body);
        }
        $clearedBodies = array_slice($bodies, 0, count($bodies) - 2);
        $this->assertContains('[cleared]', $clearedBodies);
        $this->assertSame($cleared, count(array_filter($bodies, static fn (string $b): bool => $b === '[cleared]')));
    }

    public function testCompactLeavesATwoTextBlockUserMessageUntouched(): void
    {
        $messages = $this->conversationWithRounds(6);
        $messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => '[Page: the customer is now on the cart page.]'],
                ['type' => 'text', 'text' => 'is this one better?'],
            ],
        ];
        $history = new History($messages, 0);

        $history->compact(100000, 100000);

        $last = $history->all()[array_key_last($history->all())];
        $this->assertCount(2, $last['content']);
        $this->assertSame('[Page: the customer is now on the cart page.]', $last['content'][0]['text']);
        $this->assertSame('is this one better?', $last['content'][1]['text']);
    }

    public function testRoundClosesTurnFalseWhenNoToolUses(): void
    {
        $history = new History([], 0);
        $this->assertFalse($history->roundClosesTurn([], [], $this->buildExecutor([])));
    }

    public function testRoundClosesTurnFalseWhenChipsToolIsMissing(): void
    {
        $definitions = [$this->presentationDefinition('present_products')];
        $executor = $this->buildExecutor($definitions);
        $toolUses = [['id' => 't1', 'name' => 'present_products', 'input' => []]];
        $settled = ['t1' => ToolOutcome::ok('Displayed to the customer.')];

        $history = new History([], 0);
        $this->assertFalse($history->roundClosesTurn($toolUses, $settled, $executor));
    }

    public function testRoundClosesTurnTrueWhenChipsPresentAndEveryCallEndsClean(): void
    {
        $definitions = [
            $this->presentationDefinition('present_products'),
            $this->presentationDefinition('present_suggestions'),
        ];
        $executor = $this->buildExecutor($definitions);
        $toolUses = [
            ['id' => 't1', 'name' => 'present_products', 'input' => []],
            ['id' => 't2', 'name' => 'present_suggestions', 'input' => []],
        ];
        $settled = [
            't1' => ToolOutcome::ok('Displayed to the customer.'),
            't2' => ToolOutcome::ok('Displayed to the customer.'),
        ];

        $history = new History([], 0);
        $this->assertTrue($history->roundClosesTurn($toolUses, $settled, $executor));
    }

    public function testRoundClosesTurnFalseWhenOneCallIsNotClean(): void
    {
        $definitions = [
            $this->presentationDefinition('present_products'),
            $this->presentationDefinition('present_suggestions'),
        ];
        $executor = $this->buildExecutor($definitions);
        $toolUses = [
            ['id' => 't1', 'name' => 'present_products', 'input' => []],
            ['id' => 't2', 'name' => 'present_suggestions', 'input' => []],
        ];
        $settled = [
            't1' => ToolOutcome::error('Invalid payload'),
            't2' => ToolOutcome::ok('Displayed to the customer.'),
        ];

        $history = new History([], 0);
        $this->assertFalse($history->roundClosesTurn($toolUses, $settled, $executor));
    }

    public function testRoundClosesTurnFalseWhenACallWasNeverSettled(): void
    {
        $definitions = [$this->presentationDefinition('present_suggestions')];
        $executor = $this->buildExecutor($definitions);
        $toolUses = [['id' => 't1', 'name' => 'present_suggestions', 'input' => []]];

        $history = new History([], 0);
        $this->assertFalse($history->roundClosesTurn($toolUses, [], $executor));
    }

    private function conversationWithRounds(int $rounds): array
    {
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'find tents']]]];
        for ($index = 0; $index < $rounds; $index++) {
            $callId = 't' . $index;
            $messages[] = [
                'role' => 'assistant',
                'content' => [['type' => 'tool_use', 'id' => $callId, 'name' => 'x', 'input' => []]],
            ];
            $messages[] = [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $callId,
                    'content' => str_repeat('x', 1000),
                    'is_error' => false,
                ]],
            ];
        }
        $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'and a stove']]];
        return $messages;
    }

    private function toolResultBodies(array $messages): array
    {
        $bodies = [];
        foreach ($messages as $message) {
            if (!is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                    $bodies[] = $block['content'];
                }
            }
        }
        return $bodies;
    }

    private function presentationDefinition(string $name): Definition
    {
        return new Definition($name, 'desc', ['type' => 'object'], 'presentation', null, [], false, false, 0);
    }

    /**
     * @param Definition[] $definitions
     */
    private function buildExecutor(array $definitions): Executor
    {
        $storeConfig = $this->storeConfig();
        $provider = new class ($definitions) implements ToolProviderInterface {
            public function __construct(private readonly array $definitions)
            {
            }

            public function getTools(AgentConfig $config): array
            {
                return $this->definitions;
            }
        };
        $registry = new ToolRegistry([$provider], $storeConfig);
        $sanitizer = new Sanitizer();
        $serializer = new Serializer(new Fence($sanitizer));
        $presentationRegistry = new PresentationRegistry(
            new Products($this->createMock(LoggerInterface::class), $sanitizer),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions($sanitizer)
        );
        $validator = new Validator();
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $runner = new Runner($presentationRegistry, $validator, $backend, $storeConfig);
        $provenance = new Provenance();
        $logger = $this->createMock(LoggerInterface::class);
        $context = new SessionContext('sess', null, 1, 1, new PageContext(), new \DateTimeImmutable());
        $state = new SessionState();
        $config = new AgentConfig();

        return new Executor($registry, $runner, $validator, $provenance, $sanitizer, $logger, $context, $state, $config);
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
}
