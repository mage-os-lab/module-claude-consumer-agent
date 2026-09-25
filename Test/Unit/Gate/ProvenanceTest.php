<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Gate;

use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use PHPUnit\Framework\TestCase;

final class ProvenanceTest extends TestCase
{
    public function testUnseenProductIsHeld(): void
    {
        $gate = new Provenance();
        $state = new SessionState();
        $held = $gate->check($state, 'p-1');
        $this->assertNotNull($held);
        $this->assertSame(Provenance::NAME, $held->blocked);
        $this->assertStringContainsString('p-1', $held->resultText);
        $this->assertStringContainsString('catalog or order tools', $held->resultText);
        $this->assertStringContainsString('get_product_details', $held->resultText);
        $this->assertStringContainsString('text search does not match product ids', $held->resultText);
        $this->assertStringContainsString('search or order history', $held->resultText);
    }

    public function testSeenProductPasses(): void
    {
        $gate = new Provenance();
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'p-1', 'title' => 'Thing', 'price' => 1.0]]);
        $this->assertNull($gate->check($state, 'p-1'));
    }
}
