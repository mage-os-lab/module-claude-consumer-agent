<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\StoreContact;
use PHPUnit\Framework\TestCase;

final class StoreContactTest extends TestCase
{
    public function testJoinsPhoneAndEmailWhenBothPresent(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'general/store_information/phone') {
                    return '555-0100';
                }
                if ($path === 'trans_email/ident_general/email') {
                    return 'help@example.test';
                }
                return null;
            }
        );

        $provider = new StoreContact($scopeConfig);

        $this->assertSame('Store contact: phone 555-0100, email help@example.test.', $provider->line(1));
    }

    public function testPhoneOnly(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'general/store_information/phone' ? '555-0100' : null
        );

        $provider = new StoreContact($scopeConfig);

        $this->assertSame('Store contact: phone 555-0100.', $provider->line(1));
    }

    public function testReturnsNullWhenBothAreEmpty(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $provider = new StoreContact($scopeConfig);

        $this->assertNull($provider->line(1));
    }
}
