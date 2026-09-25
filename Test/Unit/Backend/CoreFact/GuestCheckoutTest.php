<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\GuestCheckout;
use PHPUnit\Framework\TestCase;

final class GuestCheckoutTest extends TestCase
{
    public function testAllowedWhenFlagIsOn(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);

        $provider = new GuestCheckout($scopeConfig);

        $this->assertSame('Guest checkout: allowed.', $provider->line(1));
    }

    public function testAccountRequiredWhenFlagIsOff(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $provider = new GuestCheckout($scopeConfig);

        $this->assertSame('Guest checkout: an account is required.', $provider->line(1));
    }
}
