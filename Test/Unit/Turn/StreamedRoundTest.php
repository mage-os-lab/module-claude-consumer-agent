<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use MageOS\AiShoppingAssistant\Model\Agent\Turn\Exception\ApiStreamError;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\UnreadableToolInput;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRound;
use MageOS\AiShoppingAssistant\Model\Client\Fixtures;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use PHPUnit\Framework\TestCase;

final class StreamedRoundTest extends TestCase
{
    private const FIXTURES = ['text-only', 'tool-use', 'presentation', 'refusal', 'max-tokens'];

    public function testEveryFixtureFeedsWithoutThrowingAndProducesAnAssistantMessage(): void
    {
        foreach (self::FIXTURES as $name) {
            $round = new StreamedRound();
            foreach (Fixtures::load($name) as $rawEvent) {
                $round->feed($rawEvent);
            }
            $message = $round->assistantMessage();
            $this->assertNotNull($message, $name . ' should produce an assistant message');
            $this->assertSame('assistant', $message['role']);
            $this->assertNotNull($round->stopReason(), $name . ' should have a stop reason');
        }
    }

    public function testTextOnlyFixtureAssemblesTextAndUsesEndTurn(): void
    {
        $round = new StreamedRound();
        foreach (Fixtures::load('text-only') as $rawEvent) {
            $round->feed($rawEvent);
        }
        $message = $round->assistantMessage();
        $this->assertSame(
            [['type' => 'text', 'text' => 'The weather today is sunny with a light breeze.']],
            $message['content']
        );
        $this->assertSame('end_turn', $round->stopReason());
        $this->assertSame([], $round->toolUses());
    }

    public function testToolUseFixtureAccumulatesInputAcrossDeltas(): void
    {
        $round = new StreamedRound();
        foreach (Fixtures::load('tool-use') as $rawEvent) {
            $round->feed($rawEvent);
        }
        $toolUses = $round->toolUses();
        $this->assertCount(1, $toolUses);
        $this->assertSame('search_products', $toolUses[0]['name']);
        $this->assertSame('toolu_01search000000000000001', $toolUses[0]['id']);
        $this->assertSame(['query' => 'walnut desk lamp', 'limit' => 5], $toolUses[0]['input']);
        $this->assertSame('tool_use', $round->stopReason());
    }

    public function testPresentationFixtureCapturesTwoToolUsesInOrder(): void
    {
        $round = new StreamedRound();
        foreach (Fixtures::load('presentation') as $rawEvent) {
            $round->feed($rawEvent);
        }
        $toolUses = $round->toolUses();
        $this->assertCount(2, $toolUses);
        $this->assertSame('present_products', $toolUses[0]['name']);
        $this->assertSame('present_suggestions', $toolUses[1]['name']);
        $this->assertSame(
            ['Show matching side tables', 'Compare these two'],
            $toolUses[1]['input']['suggestions']
        );
    }

    public function testRefusalFixtureSetsRefusalStopReason(): void
    {
        $round = new StreamedRound();
        foreach (Fixtures::load('refusal') as $rawEvent) {
            $round->feed($rawEvent);
        }
        $this->assertSame('refusal', $round->stopReason());
        $message = $round->assistantMessage();
        $this->assertSame("I can't help with that request.", $message['content'][0]['text']);
    }

    public function testMaxTokensFixtureSetsMaxTokensStopReason(): void
    {
        $round = new StreamedRound();
        foreach (Fixtures::load('max-tokens') as $rawEvent) {
            $round->feed($rawEvent);
        }
        $this->assertSame('max_tokens', $round->stopReason());
        $this->assertSame([], $round->toolUses());
    }

    public function testFeedReturnsToolUseClosedOnceInputBufferParses(): void
    {
        $round = new StreamedRound();
        $items = [];
        $items[] = $round->feed($this->blockStart(0, 'tool_use', ['id' => 'tu-1', 'name' => 'search_products']));
        $items[] = $round->feed($this->inputDelta(0, '{"query": '));
        $items[] = $round->feed($this->inputDelta(0, '"lamp"}'));
        $items[] = $round->feed($this->blockStop(0));

        $this->assertSame([], $items[0]);
        $this->assertSame([], $items[1]);
        $this->assertSame([], $items[2]);
        $this->assertCount(1, $items[3]);
        $this->assertInstanceOf(ToolUseClosed::class, $items[3][0]);
        $this->assertSame('tu-1', $items[3][0]->id);
        $this->assertSame('search_products', $items[3][0]->name);
        $this->assertSame(['query' => 'lamp'], $items[3][0]->input);
    }

    public function testUnreadableToolInputWhenBufferNeverBecomesValidJson(): void
    {
        $round = new StreamedRound();
        $round->feed($this->blockStart(0, 'tool_use', ['id' => 'tu-2', 'name' => 'present_products']));
        $round->feed($this->inputDelta(0, '{"picks": [Not JSON'));
        $items = $round->feed($this->blockStop(0));

        $this->assertCount(1, $items);
        $this->assertInstanceOf(UnreadableToolInput::class, $items[0]);
        $this->assertSame('tu-2', $items[0]->id);
        $this->assertSame('present_products', $items[0]->name);
        $this->assertSame([], $round->toolUses()[0]['input']);
    }

    public function testEmptyToolInputBufferParsesAsEmptyObject(): void
    {
        $round = new StreamedRound();
        $round->feed($this->blockStart(0, 'tool_use', ['id' => 'tu-3', 'name' => 'get_cart']));
        $items = $round->feed($this->blockStop(0));

        $this->assertCount(1, $items);
        $this->assertInstanceOf(ToolUseClosed::class, $items[0]);
        $this->assertSame([], $items[0]->input);
    }

    public function testThinkingBlockKeptOnlyWhenSignatureArrived(): void
    {
        $round = new StreamedRound();
        $round->feed($this->blockStart(0, 'thinking', []));
        $round->feed(new RawEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'Two picks fit.'],
        ]));
        $round->feed(new RawEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'signature_delta', 'signature' => 'sig-1'],
        ]));
        $round->feed($this->blockStop(0));
        $round->feed($this->blockStart(1, 'text', []));
        $round->feed(new RawEvent('content_block_delta', [
            'index' => 1,
            'delta' => ['type' => 'text_delta', 'text' => 'Here they are.'],
        ]));
        $round->feed($this->blockStop(1));

        $message = $round->assistantMessage();
        $this->assertSame(
            [
                ['type' => 'thinking', 'thinking' => 'Two picks fit.', 'signature' => 'sig-1'],
                ['type' => 'text', 'text' => 'Here they are.'],
            ],
            $message['content']
        );
    }

    public function testThinkingBlockWithoutSignatureIsDroppedFromAssistantMessage(): void
    {
        $round = new StreamedRound();
        $round->feed($this->blockStart(0, 'thinking', []));
        $round->feed(new RawEvent('content_block_delta', [
            'index' => 0,
            'delta' => ['type' => 'thinking_delta', 'thinking' => 'Never signed.'],
        ]));
        $round->feed($this->blockStop(0));
        $round->feed($this->blockStart(1, 'text', []));
        $round->feed(new RawEvent('content_block_delta', [
            'index' => 1,
            'delta' => ['type' => 'text_delta', 'text' => 'Only this.'],
        ]));
        $round->feed($this->blockStop(1));

        $message = $round->assistantMessage();
        $this->assertSame([['type' => 'text', 'text' => 'Only this.']], $message['content']);
    }

    public function testUsageCountersAreCumulativeReplacementsNotSums(): void
    {
        $round = new StreamedRound();
        $round->feed(new RawEvent('message_start', [
            'message' => [
                'id' => 'msg_01usage',
                'usage' => [
                    'input_tokens' => 100,
                    'cache_creation_input_tokens' => 10,
                    'cache_read_input_tokens' => 5,
                    'output_tokens' => 1,
                ],
            ],
        ]));
        $round->feed(new RawEvent('message_delta', [
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 42],
        ]));

        $this->assertSame(
            [
                'input_tokens' => 100,
                'output_tokens' => 42,
                'cache_read_input_tokens' => 5,
                'cache_creation_input_tokens' => 10,
            ],
            $round->usage()
        );
        $this->assertSame('msg_01usage', $round->messageId());
    }

    public function testMessageIdIsExposed(): void
    {
        $round = new StreamedRound();
        $round->feed(new RawEvent('message_start', ['message' => ['id' => 'msg_abc']]));
        $this->assertSame('msg_abc', $round->messageId());
    }

    public function testErrorEventThrowsApiStreamError(): void
    {
        $round = new StreamedRound();
        $this->expectException(ApiStreamError::class);
        $this->expectExceptionMessage('overloaded');
        $round->feed(new RawEvent('error', ['error' => ['type' => 'overloaded_error', 'message' => 'overloaded']]));
    }

    public function testPingAndMessageStopProduceNoItems(): void
    {
        $round = new StreamedRound();
        $this->assertSame([], $round->feed(new RawEvent('ping', [])));
        $this->assertSame([], $round->feed(new RawEvent('message_stop', [])));
    }

    private function blockStart(int $index, string $type, array $fields): RawEvent
    {
        return new RawEvent('content_block_start', [
            'index' => $index,
            'content_block' => ['type' => $type] + $fields,
        ]);
    }

    private function inputDelta(int $index, string $partialJson): RawEvent
    {
        return new RawEvent('content_block_delta', [
            'index' => $index,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => $partialJson],
        ]);
    }

    private function blockStop(int $index): RawEvent
    {
        return new RawEvent('content_block_stop', ['index' => $index]);
    }
}
