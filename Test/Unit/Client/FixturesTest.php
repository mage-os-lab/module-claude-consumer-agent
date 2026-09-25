<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use MageOS\AiShoppingAssistant\Model\Client\Fixtures;
use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use PHPUnit\Framework\TestCase;

final class FixturesTest extends TestCase
{
    private const NAMES = ['text-only', 'tool-use', 'presentation', 'refusal', 'max-tokens'];

    public function testEveryFixtureFileParses(): void
    {
        foreach (self::NAMES as $name) {
            $events = Fixtures::load($name);
            $this->assertNotEmpty($events, $name . ' produced no events');
            foreach ($events as $event) {
                $this->assertInstanceOf(RawEvent::class, $event, $name . ' yielded a non RawEvent item');
            }
            $this->assertSame('message_start', $events[0]->type, $name . ' does not start with message_start');
            $this->assertSame('message_stop', $events[count($events) - 1]->type, $name . ' does not end with message_stop');
        }
    }

    public function testPathPointsAtSseFixtureFile(): void
    {
        $path = Fixtures::path('text-only');
        $this->assertStringEndsWith('Test/Fixtures/sse/text-only.sse', $path);
        $this->assertFileExists($path);
    }

    public function testToolUseFixtureReassemblesToolInput(): void
    {
        $events = Fixtures::load('tool-use');
        $json = '';
        foreach ($events as $event) {
            if ($event->type === 'content_block_delta' && ($event->data['delta']['type'] ?? null) === 'input_json_delta') {
                $json .= $event->data['delta']['partial_json'];
            }
        }
        $input = json_decode($json, true);
        $this->assertIsArray($input);
        $this->assertSame('walnut desk lamp', $input['query']);
        $this->assertSame(5, $input['limit']);
    }

    public function testToolUseFixtureCarriesCacheReadTokens(): void
    {
        $events = Fixtures::load('tool-use');
        $usage = $events[0]->data['message']['usage'];
        $this->assertGreaterThan(0, $usage['cache_read_input_tokens']);
    }

    public function testRefusalFixtureStopsWithRefusal(): void
    {
        $events = Fixtures::load('refusal');
        $messageDelta = $this->findByType($events, 'message_delta');
        $this->assertSame('refusal', $messageDelta->data['delta']['stop_reason']);
    }

    public function testMaxTokensFixtureStopsWithMaxTokens(): void
    {
        $events = Fixtures::load('max-tokens');
        $messageDelta = $this->findByType($events, 'message_delta');
        $this->assertSame('max_tokens', $messageDelta->data['delta']['stop_reason']);
    }

    private function findByType(array $events, string $type): RawEvent
    {
        foreach ($events as $event) {
            if ($event->type === $type) {
                return $event;
            }
        }
        $this->fail('No event of type ' . $type . ' found');
    }
}
