<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Shipping\Model\Carrier\AbstractCarrierInterface;
use Magento\Shipping\Model\Config;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\ShippingCarriers;
use PHPUnit\Framework\TestCase;

final class ShippingCarriersTest extends TestCase
{
    public function testJoinsActiveCarrierTitles(): void
    {
        $shippingConfig = $this->createMock(Config::class);
        $shippingConfig->method('getActiveCarriers')->willReturn([
            'flatrate' => $this->createMock(AbstractCarrierInterface::class),
            'freeshipping' => $this->createMock(AbstractCarrierInterface::class),
        ]);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) {
                if ($path === 'carriers/flatrate/title') {
                    return 'Flat Rate';
                }
                if ($path === 'carriers/freeshipping/title') {
                    return 'Free Shipping';
                }
                return null;
            }
        );

        $provider = new ShippingCarriers($shippingConfig, $scopeConfig);

        $this->assertSame('Shipping carriers: Flat Rate, Free Shipping.', $provider->line(1));
    }

    public function testReturnsNullWhenNoActiveCarriers(): void
    {
        $shippingConfig = $this->createMock(Config::class);
        $shippingConfig->method('getActiveCarriers')->willReturn([]);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $provider = new ShippingCarriers($shippingConfig, $scopeConfig);

        $this->assertNull($provider->line(1));
    }

    public function testSkipsCarriersWithAnEmptyTitle(): void
    {
        $shippingConfig = $this->createMock(Config::class);
        $shippingConfig->method('getActiveCarriers')->willReturn([
            'flatrate' => $this->createMock(AbstractCarrierInterface::class),
        ]);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $provider = new ShippingCarriers($shippingConfig, $scopeConfig);

        $this->assertNull($provider->line(1));
    }
}
