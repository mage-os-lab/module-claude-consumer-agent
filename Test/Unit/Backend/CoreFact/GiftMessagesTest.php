<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\GiftMessages;
use PHPUnit\Framework\TestCase;

final class GiftMessagesTest extends TestCase
{
    public function testAvailableWhenAllowOrderIsOn(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'sales/gift_options/allow_order'
        );

        $provider = new GiftMessages($scopeConfig);

        $this->assertSame('Gift messages: available at checkout.', $provider->line(1));
    }

    public function testAvailableWhenAllowItemsIsOn(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'sales/gift_options/allow_items'
        );

        $provider = new GiftMessages($scopeConfig);

        $this->assertSame('Gift messages: available at checkout.', $provider->line(1));
    }

    public function testNotOfferedWhenBothAreOff(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $provider = new GiftMessages($scopeConfig);

        $this->assertSame('Gift messages: not offered.', $provider->line(1));
    }
}
