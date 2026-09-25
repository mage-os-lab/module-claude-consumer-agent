<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent;

use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use PHPUnit\Framework\TestCase;

final class ToolOutcomeTest extends TestCase
{
    public function testOkDefaults(): void
    {
        $outcome = ToolOutcome::ok('done');
        $this->assertSame('done', $outcome->resultText);
        $this->assertFalse($outcome->isError);
        $this->assertNull($outcome->blocked);
        $this->assertSame([], $outcome->events);
        $this->assertNull($outcome->label);
        $this->assertSame([], $outcome->argumentsShown);
        $this->assertSame([], $outcome->products);
    }

    public function testOkWithEventsAndProducts(): void
    {
        $event = Event::progress('working');
        $products = [['product_id' => 'ABC-123']];
        $outcome = ToolOutcome::ok('done', [$event], $products);
        $this->assertSame([$event], $outcome->events);
        $this->assertSame($products, $outcome->products);
    }

    public function testError(): void
    {
        $outcome = ToolOutcome::error('failed');
        $this->assertSame('failed', $outcome->resultText);
        $this->assertTrue($outcome->isError);
        $this->assertNull($outcome->blocked);
    }

    public function testHeldIsNotAnError(): void
    {
        $outcome = ToolOutcome::held('cart_gate', 'held for review');
        $this->assertSame('held for review', $outcome->resultText);
        $this->assertFalse($outcome->isError);
        $this->assertSame('cart_gate', $outcome->blocked);
    }

    public function testWithLabelReturnsClone(): void
    {
        $original = ToolOutcome::ok('done');
        $labeled = $original->withLabel('Searching catalog');
        $this->assertNull($original->label);
        $this->assertSame('Searching catalog', $labeled->label);
        $this->assertNotSame($original, $labeled);
        $this->assertSame($original->resultText, $labeled->resultText);
    }

    public function testWithArgumentsShownReturnsClone(): void
    {
        $original = ToolOutcome::ok('done');
        $shown = $original->withArgumentsShown(['query' => 'shoes']);
        $this->assertSame([], $original->argumentsShown);
        $this->assertSame(['query' => 'shoes'], $shown->argumentsShown);
        $this->assertNotSame($original, $shown);
    }
}
