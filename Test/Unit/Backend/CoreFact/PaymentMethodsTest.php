<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend\CoreFact;

use Magento\Payment\Api\Data\PaymentMethodInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact\PaymentMethods;
use PHPUnit\Framework\TestCase;

final class PaymentMethodsTest extends TestCase
{
    private function method(string $title, string $code = ''): PaymentMethodInterface
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getTitle')->willReturn($title);
        $method->method('getCode')->willReturn($code);
        return $method;
    }

    public function testJoinsActiveMethodTitles(): void
    {
        $list = $this->createMock(PaymentMethodListInterface::class);
        $list->method('getActiveList')->willReturn([
            $this->method('Credit Card'),
            $this->method('PayPal Express'),
        ]);

        $provider = new PaymentMethods($list);

        $this->assertSame(
            'Payment methods: Credit Card, PayPal Express.',
            $provider->line(1)
        );
    }

    public function testSkipsMethodsWithAnEmptyTitle(): void
    {
        $list = $this->createMock(PaymentMethodListInterface::class);
        $list->method('getActiveList')->willReturn([
            $this->method(''),
            $this->method('PayPal Express'),
        ]);

        $provider = new PaymentMethods($list);

        $this->assertSame('Payment methods: PayPal Express.', $provider->line(1));
    }

    public function testReturnsNullWhenNoActiveMethods(): void
    {
        $list = $this->createMock(PaymentMethodListInterface::class);
        $list->method('getActiveList')->willReturn([]);

        $provider = new PaymentMethods($list);

        $this->assertNull($provider->line(1));
    }

    public function testExcludedCodesAreDropped(): void
    {
        $list = $this->createMock(PaymentMethodListInterface::class);
        $list->method('getActiveList')->willReturn([
            $this->method('Free', 'free'),
            $this->method('Credit Card', 'creditcard'),
            $this->method('PayPal Express', 'paypal_express_bml'),
        ]);

        $provider = new PaymentMethods($list, ['free', 'paypal_express_bml']);

        $this->assertSame('Payment methods: Credit Card.', $provider->line(1));
    }

    public function testDuplicateTitlesCollapseCaseInsensitivelyKeepingFirstOccurrenceAndOrder(): void
    {
        $list = $this->createMock(PaymentMethodListInterface::class);
        $list->method('getActiveList')->willReturn([
            $this->method('Credit Card', 'creditcard'),
            $this->method('credit card', 'creditcard_vault'),
            $this->method('PayPal Express', 'paypal_express'),
        ]);

        $provider = new PaymentMethods($list);

        $this->assertSame(
            'Payment methods: Credit Card, PayPal Express.',
            $provider->line(1)
        );
    }
}
