<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent;

use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\Event\SseFrame;
use PHPUnit\Framework\TestCase;

final class SseFrameTest extends TestCase
{
    public function testEncodeShapesFrame(): void
    {
        $event = Event::textDelta('hello');
        $frame = SseFrame::encode($event);
        $this->assertSame("event: text_delta\ndata: {\"text\":\"hello\"}\n\n", $frame);
    }

    public function testEncodeUsesUnescapedUnicodeAndSlashes(): void
    {
        $event = Event::progress("caf\u{e9} / done");
        $frame = SseFrame::encode($event);
        $this->assertStringContainsString('/', $frame);
        $this->assertStringNotContainsString('\\/', $frame);
        $this->assertStringContainsString('café', $frame);
    }

    public function testEncodeEndsWithBlankLine(): void
    {
        $event = Event::error('down');
        $frame = SseFrame::encode($event);
        $this->assertStringEndsWith("\n\n", $frame);
        $this->assertStringStartsWith('event: error' . "\n", $frame);
    }
}
