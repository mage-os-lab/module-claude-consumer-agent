<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Backend\CatalogMapProviderInterface;
use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;
use MageOS\AiShoppingAssistant\Api\Backend\StoreFactTitleResolverInterface;
use MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\Session\SessionRepositoryInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Api\Turn\TurnLogInterface;
use MageOS\AiShoppingAssistant\Api\Tool\ToolProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\ExecutorFactory;
use MageOS\AiShoppingAssistant\Model\Agent\Executor;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\Rules;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use MageOS\AiShoppingAssistant\Model\Agent\Lexicon;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Runner;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CatalogMap;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CoreFacts;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StoreFactsBlock;
use MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry as ToolRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception\ApiStreamError;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory;
use MageOS\AiShoppingAssistant\Model\Client\Exception\ServerError;
use MageOS\AiShoppingAssistant\Model\Client\Exception\Transport;
use MageOS\AiShoppingAssistant\Model\Client\FakeClient;
use MageOS\AiShoppingAssistant\Model\Client\Fixtures;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use MageOS\AiShoppingAssistant\Model\Client\Sleeper;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Session\Binding;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Message as MessageResource;
use MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class OrchestratorTest extends TestCase
{
    public function testTextTurnYieldsTextDeltaThenTurnComplete(): void
    {
        $client = new FakeClient([FakeClient::textRound('Hello there.', 'end_turn')]);
        [$orchestrator] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame('text_delta', $events[0]->type);
        $this->assertSame('Hello there.', $events[0]->data['text']);
        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);
        $this->assertSame('end_turn', $last->data['stop_reason']);
        $this->assertSame('sess-1', $last->data['session']);

        $system = $client->calls[0]['system'];
        $this->assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $system[1]);
    }

    public function testStreamTurnRemembersTheCustomerMessageBeforeTheRounds(): void
    {
        $client = new FakeClient([FakeClient::textRound('Hello there.', 'end_turn')]);
        [$orchestrator] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();

        iterator_to_array($orchestrator->streamTurn($binding, 'Add Pulcina to cart', $binding->context), false);

        $this->assertSame(['Add Pulcina to cart'], $binding->state->recentCustomerText);
    }

    public function testPageChangeAppendsANoteBeforeTheCustomerTextInTheFirstUserMessage(): void
    {
        $client = new FakeClient([FakeClient::textRound('Sure.', 'end_turn')]);
        [$orchestrator, $resource] = $this->buildOrchestrator($client, []);
        $page = PageContext::fromArray([
            'page_type' => 'product',
            'product_id' => 'BK-2',
            'product_name' => 'The Interior Design Handbook',
        ]);
        $binding = $this->binding($page);
        $binding->state->lastPage = [
            'page_type' => 'product',
            'product_id' => 'BK-1',
            'product_name' => 'Resident Dog',
        ];

        $captured = null;
        $resource->method('insertMany')->with(
            $this->anything(),
            $this->callback(static function (array $messages) use (&$captured): bool {
                $captured = $messages;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'is this one better?', $binding->context), false);

        $firstUserMessage = $captured[0];
        $this->assertSame('user', $firstUserMessage['role']);
        $this->assertCount(2, $firstUserMessage['content']);
        $this->assertStringStartsWith(PageNote::PREFIX, $firstUserMessage['content'][0]['text']);
        $this->assertSame('is this one better?', $firstUserMessage['content'][1]['text']);
    }

    public function testNoNoteIsAppendedWhenThePageHasNotChanged(): void
    {
        $client = new FakeClient([FakeClient::textRound('Sure.', 'end_turn')]);
        [$orchestrator, $resource] = $this->buildOrchestrator($client, []);
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'BK-2']);
        $binding = $this->binding($page);
        $binding->state->lastPage = $page->toArray();

        $captured = null;
        $resource->method('insertMany')->with(
            $this->anything(),
            $this->callback(static function (array $messages) use (&$captured): bool {
                $captured = $messages;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'tell me more', $binding->context), false);

        $firstUserMessage = $captured[0];
        $this->assertCount(1, $firstUserMessage['content']);
        $this->assertSame('tell me more', $firstUserMessage['content'][0]['text']);
    }

    public function testLastPageIsWrittenAfterEveryTurn(): void
    {
        $client = new FakeClient([FakeClient::textRound('Sure.', 'end_turn')]);
        [$orchestrator] = $this->buildOrchestrator($client, []);
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'BK-2']);
        $binding = $this->binding($page);

        iterator_to_array($orchestrator->streamTurn($binding, 'hi', $binding->context), false);

        $this->assertSame($page->toArray(), $binding->state->lastPage);
    }

    public function testToolTurnWithTwoCallsDispatchesBothAndAppendsToolResultsInOneUserMessage(): void
    {
        $toolUses = [
            ['id' => 'tu1', 'name' => 'tool_a', 'input' => ['x' => 1]],
            ['id' => 'tu2', 'name' => 'tool_b', 'input' => ['y' => 2]],
        ];
        $client = new FakeClient([
            FakeClient::toolRound($toolUses),
            FakeClient::textRound('Done.', 'end_turn'),
        ]);
        $definitions = [
            $this->definitionForHandler('tool_a', ToolOutcome::ok('a done')),
            $this->definitionForHandler('tool_b', ToolOutcome::ok('b done')),
        ];
        [$orchestrator, $resource] = $this->buildOrchestrator($client, $definitions);
        $binding = $this->binding();

        $captured = null;
        $resource->expects($this->once())->method('insertMany')->with(
            $this->anything(),
            $this->callback(static function (array $messages) use (&$captured): bool {
                $captured = $messages;
                return true;
            })
        );

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'do both', $binding->context), false);

        $this->assertNotNull($captured);
        $toolResultMessage = $this->findToolResultMessage($captured);
        $this->assertNotNull($toolResultMessage);
        $this->assertCount(2, $toolResultMessage['content']);
        $this->assertSame('a done', $toolResultMessage['content'][0]['content']);
        $this->assertSame('b done', $toolResultMessage['content'][1]['content']);
        $this->assertSame('turn_complete', $events[count($events) - 1]->type);
        $this->assertCount(2, $client->calls);

        $tools = $client->calls[0]['tools'];
        $this->assertArrayNotHasKey('cache_control', $tools[0]);
        $this->assertSame(['type' => 'ephemeral'], $tools[1]['cache_control']);

        $secondCallMessages = $client->calls[1]['messages'];
        $this->assertGreaterThanOrEqual(2, count($secondCallMessages));
        $lastMessage = $secondCallMessages[count($secondCallMessages) - 1];
        $lastBlock = $lastMessage['content'][count($lastMessage['content']) - 1];
        $this->assertSame(['type' => 'ephemeral'], $lastBlock['cache_control']);
    }

    public function testForcedFirstToolSetsToolChoiceTypeTool(): void
    {
        $client = new FakeClient([
            FakeClient::toolRound([['id' => 'tu1', 'name' => 'get_product_details', 'input' => ['product_id' => 'SKU-1']]]),
            FakeClient::textRound('Here it is.'),
        ]);
        $definitions = [$this->definitionForHandler('get_product_details', ToolOutcome::ok('details'))];
        [$orchestrator] = $this->buildOrchestrator($client, $definitions);
        $page = new PageContext(PageContextInterface::PAGE_TYPE_PRODUCT, 'SKU-1', null);
        $binding = $this->binding($page);

        iterator_to_array($orchestrator->streamTurn($binding, 'tell me about this', $binding->context), false);

        $this->assertSame(['type' => 'tool', 'name' => 'get_product_details'], $client->calls[0]['tool_choice']);

        foreach ($client->calls[0]['messages'] as $message) {
            foreach ($message['content'] as $block) {
                $this->assertArrayNotHasKey('cache_control', $block);
            }
        }
    }

    public function testLastRoundForcesToolChoiceNone(): void
    {
        $client = new FakeClient([
            FakeClient::toolRound([['id' => 'tu1', 'name' => 'tool_a', 'input' => []]]),
            FakeClient::textRound('Wrapping up.'),
        ]);
        $definitions = [$this->definitionForHandler('tool_a', ToolOutcome::ok('a done'))];
        [$orchestrator] = $this->buildOrchestrator($client, $definitions, ['ai_integration/aiagent/limits/max_tool_iterations' => '1']);
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'go', $binding->context), false);

        $this->assertCount(2, $client->calls);
        $this->assertSame(['type' => 'none'], $client->calls[1]['tool_choice']);
        $this->assertSame('turn_complete', $events[count($events) - 1]->type);
    }

    public function testCloseOnPresentationEndsTheTurn(): void
    {
        $client = new FakeClient([
            FakeClient::toolRound([
                ['id' => 'tu1', 'name' => 'present_suggestions', 'input' => ['suggestions' => ['Show more', 'Compare']]],
            ]),
        ]);
        $definitions = [$this->presentationDefinition('present_suggestions')];
        [$orchestrator] = $this->buildOrchestrator($client, $definitions);
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'help', $binding->context), false);

        $this->assertCount(1, $client->calls);
        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);
        $this->assertSame('end_turn', $last->data['stop_reason']);

        $uiEvents = array_values(array_filter($events, static fn ($event): bool => $event->type === 'ui'));
        $this->assertNotEmpty($uiEvents);
        $this->assertSame('tu1', $uiEvents[0]->data['stream_id']);
    }

    public function testMaxTokensEndsTheTurn(): void
    {
        $client = new FakeClient([Fixtures::load('max-tokens')]);
        [$orchestrator] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'hi', $binding->context), false);

        $this->assertCount(1, $client->calls);
        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);
        $this->assertSame('max_tokens', $last->data['stop_reason']);
    }

    public function testRefusalYieldsError(): void
    {
        $client = new FakeClient([Fixtures::load('refusal')]);
        [$orchestrator] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'do something bad', $binding->context), false);

        $errorEvents = array_values(array_filter($events, static fn ($event): bool => $event->type === 'error'));
        $this->assertNotEmpty($errorEvents);
        $this->assertSame('I cannot help with that request.', $errorEvents[0]->data['message']);
        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);
        $this->assertSame('refusal', $last->data['stop_reason']);
    }

    public function testClientExceptionMidTurnLeavesEveryToolUseAnsweredAndStillYieldsTurnComplete(): void
    {
        $client = new class implements MessagesClientInterface {
            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                yield new RawEvent('message_start', [
                    'message' => [
                        'id' => 'msg_x',
                        'usage' => [
                            'input_tokens' => 10,
                            'output_tokens' => 1,
                            'cache_read_input_tokens' => 0,
                            'cache_creation_input_tokens' => 0,
                        ],
                    ],
                ]);
                yield new RawEvent('content_block_start', [
                    'index' => 0,
                    'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'tool_a'],
                ]);
                yield new RawEvent('content_block_delta', [
                    'index' => 0,
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}'],
                ]);
                yield new RawEvent('content_block_stop', ['index' => 0]);
                yield new RawEvent('content_block_start', [
                    'index' => 1,
                    'content_block' => ['type' => 'tool_use', 'id' => 't2', 'name' => 'tool_b'],
                ]);
                yield new RawEvent('content_block_delta', [
                    'index' => 1,
                    'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}'],
                ]);
                throw new ServerError('boom');
            }
        };
        $definitions = [$this->definitionForHandler('tool_a', ToolOutcome::ok('a done'))];
        [$orchestrator, $resource] = $this->buildOrchestrator($client, $definitions);
        $binding = $this->binding();

        $captured = null;
        $resource->method('insertMany')->with(
            $this->anything(),
            $this->callback(static function (array $messages) use (&$captured): bool {
                $captured = $messages;
                return true;
            })
        );

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'go', $binding->context), false);

        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);

        $assistantMessage = null;
        foreach ($captured as $message) {
            if ($message['role'] === 'assistant') {
                $assistantMessage = $message;
            }
        }
        $this->assertNotNull($assistantMessage);
        $this->assertCount(2, $assistantMessage['content']);

        $resultMessage = $this->findToolResultMessage($captured);
        $this->assertNotNull($resultMessage);
        $this->assertCount(2, $resultMessage['content']);
        $byId = [];
        foreach ($resultMessage['content'] as $block) {
            $byId[$block['tool_use_id']] = $block;
        }
        $this->assertSame('a done', $byId['t1']['content']);
        $this->assertFalse($byId['t1']['is_error']);
        $this->assertSame('Interrupted before this tool finished.', $byId['t2']['content']);
        $this->assertTrue($byId['t2']['is_error']);
    }

    public function testScriptExhaustionOfFakeClientFailsTheTestRatherThanLooping(): void
    {
        $client = new FakeClient([FakeClient::toolRound([['id' => 'tu1', 'name' => 'tool_a', 'input' => []]])]);
        $definitions = [$this->definitionForHandler('tool_a', ToolOutcome::ok('a done'))];
        [$orchestrator] = $this->buildOrchestrator($client, $definitions);
        $binding = $this->binding();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FakeClient script exhausted');

        iterator_to_array($orchestrator->streamTurn($binding, 'go', $binding->context), false);
    }

    public function testTurnLogRecordsSummedUsageAndTheIncrementedTurnNumber(): void
    {
        $client = new FakeClient([FakeClient::textRound('Hello there.', 'end_turn')]);
        [$orchestrator, , $turnLog] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();
        $binding->state->turnCounter = 4;

        $captured = null;
        $turnLog->expects($this->once())->method('record')->with(
            $this->callback(static function (array $row) use (&$captured): bool {
                $captured = $row;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame('sess-1', $captured['session_id']);
        $this->assertSame(1, $captured['store_id']);
        $this->assertSame(5, $captured['turn_no']);
        $this->assertSame('claude-sonnet-5', $captured['model_id']);
        $this->assertSame(1, $captured['rounds']);
        $this->assertArrayHasKey('input_tokens', $captured);
        $this->assertArrayHasKey('output_tokens', $captured);
        $this->assertArrayHasKey('cache_creation_input_tokens', $captured);
        $this->assertArrayHasKey('cache_read_input_tokens', $captured);
        $this->assertArrayHasKey('duration_ms', $captured);
        $this->assertSame('end_turn', $captured['stop_reason']);
    }

    public function testSessionSaveFailureLogsAWarningAndKeepsThePriorTurnNumber(): void
    {
        $client = new FakeClient([FakeClient::textRound('Hello there.', 'end_turn')]);
        [$orchestrator, , $turnLog, , $logger] = $this->buildOrchestrator($client, [], [], false);
        $binding = $this->binding();
        $binding->state->turnCounter = 4;

        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('turn=5')
        );

        $captured = null;
        $turnLog->method('record')->with(
            $this->callback(static function (array $row) use (&$captured): bool {
                $captured = $row;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame(4, $captured['turn_no']);
    }

    public function testTurnLogRoundsCountsEveryModelCall(): void
    {
        $toolUses = [['id' => 'tu1', 'name' => 'tool_a', 'input' => []]];
        $client = new FakeClient([
            FakeClient::toolRound($toolUses),
            FakeClient::textRound('Done.', 'end_turn'),
        ]);
        $definitions = [$this->definitionForHandler('tool_a', ToolOutcome::ok('a done'))];
        [$orchestrator, , $turnLog] = $this->buildOrchestrator($client, $definitions);
        $binding = $this->binding();

        $captured = null;
        $turnLog->method('record')->with(
            $this->callback(static function (array $row) use (&$captured): bool {
                $captured = $row;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'do it', $binding->context), false);

        $this->assertSame(2, $captured['rounds']);
    }

    public function testTurnLogIsRecordedOnceWhenTheClientFailsMidTurn(): void
    {
        $client = new class implements MessagesClientInterface {
            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                yield new RawEvent('message_start', [
                    'message' => [
                        'id' => 'msg_x',
                        'usage' => [
                            'input_tokens' => 10,
                            'output_tokens' => 1,
                            'cache_read_input_tokens' => 0,
                            'cache_creation_input_tokens' => 0,
                        ],
                    ],
                ]);
                throw new ServerError('boom');
            }
        };
        [$orchestrator, , $turnLog] = $this->buildOrchestrator($client, []);
        $binding = $this->binding();

        $captured = null;
        $turnLog->expects($this->once())->method('record')->with(
            $this->callback(static function (array $row) use (&$captured): bool {
                $captured = $row;
                return true;
            })
        );

        iterator_to_array($orchestrator->streamTurn($binding, 'go', $binding->context), false);

        $this->assertSame('error', $captured['stop_reason']);
        $this->assertSame(1, $captured['rounds']);
    }

    public function testAStreamThatEndsBeforeAnyOutputIsRetriedOnceAndThenCompletes(): void
    {
        $client = new class implements MessagesClientInterface {
            public int $calls = 0;

            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                $this->calls++;
                if ($this->calls === 1) {
                    yield new RawEvent('message_start', ['message' => ['id' => 'msg_x', 'usage' => []]]);
                    throw new Transport('The model stream ended before message_stop.');
                }
                yield from FakeClient::textRound('Here you go.', 'end_turn');
            }
        };
        [$orchestrator, , $turnLog, , , $sleeper] = $this->buildOrchestrator($client, []);
        $sleeper->expects($this->once())->method('sleep');
        $captured = null;
        $turnLog->method('record')->with(
            $this->callback(static function (array $row) use (&$captured): bool {
                $captured = $row;
                return true;
            })
        );
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame(2, $client->calls);
        $types = array_map(static fn ($event): string => $event->type, $events);
        $this->assertNotContains('error', $types);
        $last = $events[count($events) - 1];
        $this->assertSame('turn_complete', $last->type);
        $this->assertSame('end_turn', $last->data['stop_reason']);
        $this->assertSame(1, $captured['rounds']);
    }

    public function testAnOverloadedStreamErrorIsRetriedOnceAndItsTypeReachesTheLog(): void
    {
        $client = new class implements MessagesClientInterface {
            public int $calls = 0;

            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                $this->calls++;
                yield new RawEvent('message_start', ['message' => ['id' => 'msg_x', 'usage' => []]]);
                throw new ApiStreamError('overloaded_error', 'Overloaded');
            }
        };
        [$orchestrator, , , , $logger, $sleeper] = $this->buildOrchestrator($client, []);
        $sleeper->expects($this->once())->method('sleep');
        $warnings = [];
        $logger->method('warning')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame(2, $client->calls);
        $retried = array_values(array_filter(
            $warnings,
            static fn (string $line): bool => str_contains($line, 'model call retried')
        ));
        $failed = array_values(array_filter(
            $warnings,
            static fn (string $line): bool => str_contains($line, 'model call failed')
        ));
        $this->assertCount(1, $retried);
        $this->assertStringContainsString('error=overloaded_error: Overloaded', $retried[0]);
        $this->assertCount(1, $failed);
        $this->assertStringContainsString('error=overloaded_error: Overloaded', $failed[0]);
        $errorEvents = array_values(array_filter($events, static fn ($event): bool => $event->type === 'error'));
        $this->assertSame(
            'The assistant is busy. Please try again in a few seconds.',
            $errorEvents[0]->data['message']
        );
    }

    public function testAStreamErrorTypeOutsideTheRetryListIsNotRetried(): void
    {
        $client = new class implements MessagesClientInterface {
            public int $calls = 0;

            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                $this->calls++;
                yield new RawEvent('message_start', ['message' => ['id' => 'msg_x', 'usage' => []]]);
                throw new ApiStreamError('invalid_request_error', 'Bad input');
            }
        };
        [$orchestrator, , , , , $sleeper] = $this->buildOrchestrator($client, []);
        $sleeper->expects($this->never())->method('sleep');
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame(1, $client->calls);
        $errorEvents = array_values(array_filter($events, static fn ($event): bool => $event->type === 'error'));
        $this->assertCount(1, $errorEvents);
    }

    public function testARoundThatAlreadyStreamedTextIsNotRetried(): void
    {
        $client = new class implements MessagesClientInterface {
            public int $calls = 0;

            public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
            {
                $this->calls++;
                yield new RawEvent('message_start', ['message' => ['id' => 'msg_x', 'usage' => []]]);
                yield new RawEvent('content_block_start', [
                    'index' => 0,
                    'content_block' => ['type' => 'text', 'text' => ''],
                ]);
                yield new RawEvent('content_block_delta', [
                    'index' => 0,
                    'delta' => ['type' => 'text_delta', 'text' => 'Half a sen'],
                ]);
                throw new Transport('The model stream ended before message_stop.');
            }
        };
        [$orchestrator, , , , , $sleeper] = $this->buildOrchestrator($client, []);
        $sleeper->expects($this->never())->method('sleep');
        $binding = $this->binding();

        $events = iterator_to_array($orchestrator->streamTurn($binding, 'Hi', $binding->context), false);

        $this->assertSame(1, $client->calls);
        $textEvents = array_values(array_filter($events, static fn ($event): bool => $event->type === 'text_delta'));
        $this->assertCount(1, $textEvents);
        $this->assertSame('Half a sen', $textEvents[0]->data['text']);
    }

    private function findToolResultMessage(array $messages): ?array
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $message['content'] ?? [];
            if (isset($content[0]['type']) && $content[0]['type'] === 'tool_result') {
                return $message;
            }
        }
        return null;
    }

    private function binding(?PageContext $page = null): Binding
    {
        $context = new SessionContext('sess-1', null, 1, 1, $page ?? new PageContext(), new \DateTimeImmutable());
        return new Binding('sess-1', null, new SessionState(), true, 0, $context);
    }

    private function definitionForHandler(string $name, ToolOutcome $outcome): Definition
    {
        $handler = new class ($outcome) implements HandlerInterface {
            public function __construct(private readonly ToolOutcome $outcome)
            {
            }

            public function handle(
                array $input,
                SessionContext $context,
                SessionState $state,
                AgentConfig $config
            ): ToolOutcome {
                return $this->outcome;
            }
        };
        return new Definition($name, 'desc', ['type' => 'object'], 'read', $handler, [], false, false, 0);
    }

    private function presentationDefinition(string $name): Definition
    {
        return new Definition($name, 'desc', ['type' => 'object'], 'presentation', null, [], false, false, 0);
    }

    /**
     * @param Definition[] $definitions
     * @return array{0: Orchestrator, 1: MockObject, 2: MockObject}
     */
    private function buildOrchestrator(
        MessagesClientInterface $client,
        array $definitions,
        array $configOverrides = [],
        bool $saveSucceeds = true
    ): array {
        $storeConfig = $this->storeConfig($configOverrides);
        $backend = $this->createMock(StorefrontBackendInterface::class);

        $provider = new class ($definitions) implements ToolProviderInterface {
            public function __construct(private readonly array $definitions)
            {
            }

            public function getTools(AgentConfig $config): array
            {
                return $this->definitions;
            }
        };
        $toolRegistry = new ToolRegistry([$provider], $storeConfig);

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
        $runner = new Runner($presentationRegistry, $validator, $backend, $storeConfig);
        $provenance = new Provenance();
        $executorLogger = $this->createMock(LoggerInterface::class);

        $config = $storeConfig->agent(1);
        $context = new SessionContext('sess-1', null, 1, 1, new PageContext(), new \DateTimeImmutable());
        $state = new SessionState();
        $executor = new Executor(
            $toolRegistry,
            $runner,
            $validator,
            $provenance,
            $sanitizer,
            $executorLogger,
            $context,
            $state,
            $config
        );
        $executorFactory = $this->createMock(ExecutorFactory::class);
        $executorFactory->method('create')->willReturn($executor);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $skillLoader = new Loader(
            [],
            $this->createMock(ComponentRegistrarInterface::class),
            $this->createMock(File::class),
            new FrontMatter()
        );
        $staticSystem = new StaticSystem(
            $storeConfig,
            new SkillRegistry($skillLoader),
            $cache,
            new Json(),
            new Fence($sanitizer),
            $this->createMock(CatalogMapProviderInterface::class),
            new CatalogMap(),
            new CoreFacts([]),
            new StoreFactsBlock(),
            $this->createMock(StoreFactTitleResolverInterface::class)
        );
        $dynamicContext = new DynamicContext(new Fence($sanitizer));
        $assembly = new Assembly();
        $rules = new Rules(
            new Lexicon($storeConfig),
            new SkuCandidates(),
            $this->createMock(SkuMatcherInterface::class)
        );

        $messageResource = $this->createMock(MessageResource::class);
        $messageResource->method('loadBySession')->willReturn([]);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->createMock(AdapterInterface::class));
        $transcripts = new TranscriptRepository($messageResource, $resourceConnection);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('save')->willReturn($saveSucceeds);

        $logger = $this->createMock(LoggerInterface::class);
        $turnLog = $this->createMock(TurnLogInterface::class);
        $sleeper = $this->createMock(Sleeper::class);

        $orchestrator = new Orchestrator(
            $client,
            $backend,
            $staticSystem,
            $dynamicContext,
            $assembly,
            new PageNote($sanitizer),
            $toolRegistry,
            $executorFactory,
            $rules,
            $transcripts,
            $sessions,
            $storeConfig,
            $logger,
            new StreamedRoundFactory(),
            $turnLog,
            $sleeper
        );

        return [$orchestrator, $messageResource, $turnLog, $sessions, $logger, $sleeper];
    }

    private function storeConfig(array $valueOverrides = []): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): mixed => $valueOverrides[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }
}
