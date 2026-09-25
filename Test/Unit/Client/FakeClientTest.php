<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use MageOS\AiShoppingAssistant\Model\Client\FakeClient;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use PHPUnit\Framework\TestCase;

final class FakeClientTest extends TestCase
{
    public function testStreamRecordsRequestAndReplaysRound(): void
    {
        $client = new FakeClient([FakeClient::textRound('hello')]);
        $request = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $events = iterator_to_array($client->stream($request), false);

        $this->assertSame([$request], $client->calls);
        $this->assertNotEmpty($events);
        $this->assertSame('message_start', $events[0]->type);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testStreamThrowsWhenScriptExhausted(): void
    {
        $client = new FakeClient();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FakeClient script exhausted');

        iterator_to_array($client->stream(['messages' => []]), false);
    }

    public function testStreamThrowsOnSecondCallWhenOnlyOneRoundScripted(): void
    {
        $client = new FakeClient([FakeClient::textRound('hello')]);
        iterator_to_array($client->stream(['messages' => []]), false);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FakeClient script exhausted');

        iterator_to_array($client->stream(['messages' => []]), false);
    }

    public function testAddRoundAppendsAndReturnsSelf(): void
    {
        $client = new FakeClient();
        $result = $client->addRound(FakeClient::textRound('a'));

        $this->assertSame($client, $result);

        $events = iterator_to_array($client->stream(['messages' => []]), false);
        $this->assertSame('a', $events[2]->data['delta']['text']);
    }

    public function testTextRoundEndsWithConfiguredStopReason(): void
    {
        $round = FakeClient::textRound('hi there', 'end_turn');
        $messageDelta = $round[count($round) - 2];

        $this->assertSame('message_delta', $messageDelta->type);
        $this->assertSame('end_turn', $messageDelta->data['delta']['stop_reason']);
    }

    public function testToolRoundBuildsOneToolUsePerEntry(): void
    {
        $round = FakeClient::toolRound(
            [
                ['name' => 'search_products', 'id' => 'toolu_01', 'input' => ['query' => 'lamp']],
                ['name' => 'get_time', 'id' => 'toolu_02', 'input' => []],
            ]
        );

        $toolUseStarts = array_values(array_filter(
            $round,
            static fn (RawEvent $event): bool => $event->type === 'content_block_start'
                && ($event->data['content_block']['type'] ?? null) === 'tool_use'
        ));

        $this->assertCount(2, $toolUseStarts);
        $this->assertSame('search_products', $toolUseStarts[0]->data['content_block']['name']);
        $this->assertSame('get_time', $toolUseStarts[1]->data['content_block']['name']);

        $messageDelta = $round[count($round) - 2];
        $this->assertSame('tool_use', $messageDelta->data['delta']['stop_reason']);
    }

    public function testToolRoundSplitsInputAcrossTwoFragments(): void
    {
        $round = FakeClient::toolRound(
            [['name' => 'search_products', 'id' => 'toolu_01', 'input' => ['query' => 'walnut desk lamp']]]
        );

        $deltas = array_values(array_filter(
            $round,
            static fn (RawEvent $event): bool => $event->type === 'content_block_delta'
                && ($event->data['delta']['type'] ?? null) === 'input_json_delta'
        ));

        $this->assertCount(2, $deltas);
        $joined = $deltas[0]->data['delta']['partial_json'] . $deltas[1]->data['delta']['partial_json'];
        $this->assertSame(['query' => 'walnut desk lamp'], json_decode($joined, true));
    }

    public function testToolRoundWithThinkingEmitsAThinkingBlockFirst(): void
    {
        $round = FakeClient::toolRound(
            [['name' => 'search_products', 'id' => 'toolu_01', 'input' => ['query' => 'lamp']]],
            '',
            ['thinking' => 'Weighing two options.', 'signature' => 'sig-abc']
        );

        $this->assertSame('content_block_start', $round[1]->type);
        $this->assertSame('thinking', $round[1]->data['content_block']['type']);
        $this->assertSame(0, $round[1]->data['index']);
        $this->assertSame('content_block_delta', $round[2]->type);
        $this->assertSame('thinking_delta', $round[2]->data['delta']['type']);
        $this->assertSame('Weighing two options.', $round[2]->data['delta']['thinking']);
        $this->assertSame('content_block_delta', $round[3]->type);
        $this->assertSame('signature_delta', $round[3]->data['delta']['type']);
        $this->assertSame('sig-abc', $round[3]->data['delta']['signature']);
        $this->assertSame('content_block_stop', $round[4]->type);
        $this->assertSame(0, $round[4]->data['index']);
        $this->assertSame('content_block_start', $round[5]->type);
        $this->assertSame('tool_use', $round[5]->data['content_block']['type']);
        $this->assertSame(1, $round[5]->data['index']);
    }

    public function testFromFixtureLoadsRecordedEvents(): void
    {
        $round = FakeClient::fromFixture('text-only');

        $this->assertNotEmpty($round);
        $this->assertSame('message_start', $round[0]->type);
    }
}
