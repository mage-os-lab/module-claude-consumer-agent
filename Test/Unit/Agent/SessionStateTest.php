<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Agent;

use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use PHPUnit\Framework\TestCase;

final class SessionStateTest extends TestCase
{
    public function testRememberProductsStoresRecords(): void
    {
        $state = new SessionState();
        $state->rememberProducts([
            ['product_id' => 'A-1', 'title' => 'First'],
            ['product_id' => 'A-2', 'title' => 'Second'],
        ]);
        $this->assertTrue($state->hasSeen('A-1'));
        $this->assertTrue($state->hasSeen('A-2'));
        $this->assertSame(['product_id' => 'A-1', 'title' => 'First'], $state->seen('A-1'));
    }

    public function testRememberProductsMovesReSeenIdToNewest(): void
    {
        $state = new SessionState();
        $state->rememberProducts([
            ['product_id' => 'A-1'],
            ['product_id' => 'A-2'],
        ]);
        $state->rememberProducts([['product_id' => 'A-1', 'title' => 'Updated']]);
        $keys = array_keys($state->seenProducts);
        $this->assertSame(['A-2', 'A-1'], $keys);
        $this->assertSame(['product_id' => 'A-1', 'title' => 'Updated'], $state->seen('A-1'));
    }

    public function testRememberProductsDropsOldestWhenOverCap(): void
    {
        $state = new SessionState();
        $records = [];
        for ($i = 1; $i <= 201; $i++) {
            $records[] = ['product_id' => 'P-' . $i];
        }
        $state->rememberProducts($records);
        $this->assertCount(200, $state->seenProducts);
        $this->assertFalse($state->hasSeen('P-1'));
        $this->assertTrue($state->hasSeen('P-2'));
        $this->assertTrue($state->hasSeen('P-201'));
    }

    public function testHasSeenReturnsFalseForUnknownId(): void
    {
        $state = new SessionState();
        $this->assertFalse($state->hasSeen('X-1'));
        $this->assertNull($state->seen('X-1'));
    }

    public function testHasSeenCaseInsensitive(): void
    {
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'abc-123']]);
        $this->assertTrue($state->hasSeenCaseInsensitive('ABC-123'));
        $this->assertFalse($state->hasSeen('ABC-123'));
    }

    public function testToJsonProducesExpectedKeys(): void
    {
        $state = new SessionState(
            ['A-1' => ['product_id' => 'A-1']],
            3,
            ['page_type' => 'search'],
            'side_cart',
            ['Add Pulcina to cart']
        );
        $decoded = json_decode($state->toJson(), true);
        $this->assertSame(
            ['seen_products', 'turn_counter', 'last_page', 'surface_view', 'recent_customer_text'],
            array_keys($decoded)
        );
        $this->assertSame(['A-1' => ['product_id' => 'A-1']], $decoded['seen_products']);
        $this->assertSame(3, $decoded['turn_counter']);
        $this->assertSame(['page_type' => 'search'], $decoded['last_page']);
        $this->assertSame('side_cart', $decoded['surface_view']);
        $this->assertSame(['Add Pulcina to cart'], $decoded['recent_customer_text']);
    }

    public function testFromJsonRoundTrips(): void
    {
        $state = new SessionState(
            ['A-1' => ['product_id' => 'A-1']],
            3,
            ['page_type' => 'search'],
            'side_cart',
            ['Add Pulcina to cart']
        );
        $restored = SessionState::fromJson($state->toJson());
        $this->assertSame($state->seenProducts, $restored->seenProducts);
        $this->assertSame($state->turnCounter, $restored->turnCounter);
        $this->assertSame($state->lastPage, $restored->lastPage);
        $this->assertSame($state->surfaceView, $restored->surfaceView);
        $this->assertSame($state->recentCustomerText, $restored->recentCustomerText);
    }

    public function testFromJsonEmptyStringYieldsEmptyState(): void
    {
        $state = SessionState::fromJson('');
        $this->assertSame([], $state->seenProducts);
        $this->assertSame(0, $state->turnCounter);
        $this->assertSame([], $state->lastPage);
        $this->assertSame('', $state->surfaceView);
        $this->assertSame([], $state->recentCustomerText);
    }

    public function testFromJsonInvalidJsonYieldsEmptyState(): void
    {
        $state = SessionState::fromJson('{not valid json');
        $this->assertSame([], $state->seenProducts);
        $this->assertSame(0, $state->turnCounter);
    }

    public function testRememberCustomerTextAppendsNewestLast(): void
    {
        $state = new SessionState();
        $state->rememberCustomerText('First message');
        $state->rememberCustomerText('Second message');
        $this->assertSame(['First message', 'Second message'], $state->recentCustomerText);
    }

    public function testRememberCustomerTextDropsOldestWhenOverCap(): void
    {
        $state = new SessionState();
        for ($i = 1; $i <= 9; $i++) {
            $state->rememberCustomerText('Message ' . $i);
        }
        $this->assertCount(8, $state->recentCustomerText);
        $this->assertSame('Message 2', $state->recentCustomerText[0]);
        $this->assertSame('Message 9', $state->recentCustomerText[7]);
    }
}
