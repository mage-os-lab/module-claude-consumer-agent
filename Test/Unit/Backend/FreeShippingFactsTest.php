<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\FreeShippingFacts;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class FreeShippingFactsTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext('session-1', null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    public function testReturnsOptionWhenCarrierActive(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('50');

        $provider = new FreeShippingFacts($scopeConfig);

        $options = $provider->options($this->context(), []);

        $this->assertCount(1, $options);
        $this->assertSame(FulfillmentOptionInterface::METHOD_SHIPPING, $options[0]->getMethod());
        $this->assertSame('free shipping on orders over 50', $options[0]->getEta());
        $this->assertSame(0.0, $options[0]->getFee());
    }

    public function testReturnsNoOptionWhenCarrierInactive(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $provider = new FreeShippingFacts($scopeConfig);

        $this->assertSame([], $provider->options($this->context(), []));
    }

    public function testFormatsDecimalThreshold(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('49.99');

        $provider = new FreeShippingFacts($scopeConfig);

        $options = $provider->options($this->context(), []);

        $this->assertSame('free shipping on orders over 49.99', $options[0]->getEta());
    }
}
