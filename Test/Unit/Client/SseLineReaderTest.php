<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use MageOS\AiShoppingAssistant\Model\Client\RawEvent;
use MageOS\AiShoppingAssistant\Model\Client\SseLineReader;
use PHPUnit\Framework\TestCase;

final class SseLineReaderTest extends TestCase
{
    private function collect(iterable $chunks): array
    {
        $reader = new SseLineReader();
        return iterator_to_array($reader->read($chunks), false);
    }

    public function testFrameSplitAcrossChunksMidLine(): void
    {
        $events = $this->collect([
            "event: message_st",
            "art\ndata: {\"a\":1}\n\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(RawEvent::class, $events[0]);
        $this->assertSame('message_start', $events[0]->type);
        $this->assertSame(['a' => 1], $events[0]->data);
    }

    public function testFrameSplitAcrossChunksMidFrame(): void
    {
        $events = $this->collect([
            "event: ping\ndata: {\"type\":\"ping\"}\n",
            "\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('ping', $events[0]->type);
        $this->assertSame(['type' => 'ping'], $events[0]->data);
    }

    public function testCommentLinesAreSkipped(): void
    {
        $events = $this->collect([
            ": keep-alive\nevent: ping\ndata: {\"type\":\"ping\"}\n\n:another comment\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('ping', $events[0]->type);
    }

    public function testCrlfIsTolerated(): void
    {
        $events = $this->collect([
            "event: message_stop\r\ndata: {\"type\":\"message_stop\"}\r\n\r\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('message_stop', $events[0]->type);
        $this->assertSame(['type' => 'message_stop'], $events[0]->data);
    }

    public function testTrailingFrameWithoutBlankLineIsFlushed(): void
    {
        $events = $this->collect([
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('message_stop', $events[0]->type);
    }

    public function testMultiLineDataIsJoinedWithNewline(): void
    {
        $events = $this->collect([
            "event: message_start\ndata: {\ndata: \"a\": 1\ndata: }\n\n",
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
        $this->assertSame(['a' => 1], $events[0]->data);
    }

    public function testMultipleFramesInSequence(): void
    {
        $events = $this->collect([
            "event: content_block_delta\ndata: {\"delta\":{\"text\":\"a\"}}\n\n"
            . "event: content_block_delta\ndata: {\"delta\":{\"text\":\"b\"}}\n\n",
        ]);

        $this->assertCount(2, $events);
        $this->assertSame('a', $events[0]->data['delta']['text']);
        $this->assertSame('b', $events[1]->data['delta']['text']);
    }

    public function testEmptyChunksAreSkipped(): void
    {
        $events = $this->collect([
            '',
            "event: ping\ndata: {\"type\":\"ping\"}\n\n",
            '',
        ]);

        $this->assertCount(1, $events);
        $this->assertSame('ping', $events[0]->type);
    }

    public function testFrameExceedingTheByteCapThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $chunks = (function (): iterable {
            yield "event: content_block_delta\ndata: {\"delta\":{\"text\":\"";
            $sent = 0;
            while ($sent < 1_048_576 + 1) {
                yield str_repeat('a', 65_536);
                $sent += 65_536;
            }
        })();

        $this->collect($chunks);
    }
}
