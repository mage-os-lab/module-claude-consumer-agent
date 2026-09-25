<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent;

use MageOS\AiShoppingAssistant\Model\Agent\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testTextDelta(): void
    {
        $event = Event::textDelta('hello');
        $this->assertSame('text_delta', $event->type);
        $this->assertSame(['text' => 'hello'], $event->data);
        $this->assertSame(['type' => 'text_delta', 'data' => ['text' => 'hello']], $event->toArray());
    }

    public function testToolCallWithLabel(): void
    {
        $event = Event::toolCall('search_products', 'id1', ['query' => 'shoes'], 'Searching');
        $this->assertSame('tool_call', $event->type);
        $this->assertSame(
            ['tool' => 'search_products', 'id' => 'id1', 'input' => ['query' => 'shoes'], 'label' => 'Searching'],
            $event->data
        );
    }

    public function testToolCallWithoutLabel(): void
    {
        $event = Event::toolCall('search_products', 'id1', ['query' => 'shoes'], null);
        $this->assertArrayNotHasKey('label', $event->data);
    }

    public function testToolResultOk(): void
    {
        $event = Event::toolResult('search_products', 'id1', '3 results', false, 'ok');
        $this->assertSame(
            [
                'tool' => 'search_products',
                'id' => 'id1',
                'summary' => '3 results',
                'is_error' => false,
                'status' => 'ok',
            ],
            $event->data
        );
    }

    public function testToolResultBlockedWithReasonAndExcerpt(): void
    {
        $event = Event::toolResult(
            'add_to_cart',
            'id2',
            'held',
            true,
            'blocked',
            'cart_gate',
            'excerpt text'
        );
        $this->assertSame('cart_gate', $event->data['reason']);
        $this->assertSame('excerpt text', $event->data['excerpt']);
        $this->assertTrue($event->data['is_error']);
        $this->assertSame('blocked', $event->data['status']);
    }

    public function testUi(): void
    {
        $event = Event::ui('product_grid', ['items' => []], 'stream1');
        $this->assertSame('ui', $event->type);
        $this->assertSame(
            ['component' => 'product_grid', 'payload' => ['items' => []], 'stream_id' => 'stream1'],
            $event->data
        );
    }

    public function testCartUpdate(): void
    {
        $event = Event::cartUpdate(['items' => [], 'total' => 0]);
        $this->assertSame('cart_update', $event->type);
        $this->assertSame(['cart' => ['items' => [], 'total' => 0]], $event->data);
    }

    public function testProgress(): void
    {
        $event = Event::progress('Retrying');
        $this->assertSame('progress', $event->type);
        $this->assertSame(['message' => 'Retrying'], $event->data);
    }

    public function testTurnComplete(): void
    {
        $event = Event::turnComplete('end_turn', ['input_tokens' => 10], 500, 2);
        $this->assertSame(
            [
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10],
                'elapsed_ms' => 500,
                'results_cleared' => 2,
            ],
            $event->data
        );
    }

    public function testTurnCompleteWithoutSessionOmitsTheSessionKey(): void
    {
        $event = Event::turnComplete('end_turn', [], 500, 0);
        $this->assertArrayNotHasKey('session', $event->data);
    }

    public function testTurnCompleteWithSessionIncludesTheSessionKey(): void
    {
        $event = Event::turnComplete('end_turn', [], 500, 0, 'sess-1');
        $this->assertSame('sess-1', $event->data['session']);
    }

    public function testErrorWithoutRetryAfter(): void
    {
        $event = Event::error('busy');
        $this->assertSame(['message' => 'busy'], $event->data);
    }

    public function testErrorWithRetryAfter(): void
    {
        $event = Event::error('busy', 30);
        $this->assertSame(['message' => 'busy', 'retry_after' => 30], $event->data);
    }
}
