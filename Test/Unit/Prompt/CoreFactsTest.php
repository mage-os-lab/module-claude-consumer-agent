<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CoreFacts;
use PHPUnit\Framework\TestCase;

final class CoreFactsTest extends TestCase
{
    private function provider(?string $line): CoreFactProviderInterface
    {
        $provider = $this->createMock(CoreFactProviderInterface::class);
        $provider->method('line')->willReturn($line);
        return $provider;
    }

    public function testDropsNullsAndKeepsTheRest(): void
    {
        $coreFacts = new CoreFacts([
            '10' => $this->provider('Payment methods: Credit card.'),
            '20' => $this->provider(null),
            '30' => $this->provider('Guest checkout: allowed.'),
        ]);

        $this->assertSame(
            ['Payment methods: Credit card.', 'Guest checkout: allowed.'],
            $coreFacts->lines(1)
        );
    }

    public function testOrdersByProviderKey(): void
    {
        $coreFacts = new CoreFacts([
            '30_gift_messages' => $this->provider('Gift messages: not offered.'),
            '10_payment_methods' => $this->provider('Payment methods: Credit card.'),
            '20_shipping_carriers' => $this->provider('Shipping carriers: Flat Rate.'),
        ]);

        $this->assertSame(
            [
                'Payment methods: Credit card.',
                'Shipping carriers: Flat Rate.',
                'Gift messages: not offered.',
            ],
            $coreFacts->lines(1)
        );
    }

    public function testEmptyPoolReturnsEmptyArray(): void
    {
        $coreFacts = new CoreFacts([]);
        $this->assertSame([], $coreFacts->lines(1));
    }

    public function testAllNullsReturnsEmptyArray(): void
    {
        $coreFacts = new CoreFacts([
            '10' => $this->provider(null),
            '20' => $this->provider(null),
        ]);
        $this->assertSame([], $coreFacts->lines(1));
    }
}
