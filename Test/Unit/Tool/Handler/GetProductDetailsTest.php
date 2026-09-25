<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\GetProductDetails;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use PHPUnit\Framework\TestCase;

final class GetProductDetailsTest extends TestCase
{
    private const SERIALIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Serializer::class;
    private const FENCE_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence::class;
    private const SANITIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer::class;

    protected function setUp(): void
    {
        foreach ([self::SERIALIZER_CLASS, self::FENCE_CLASS, self::SANITIZER_CLASS] as $class) {
            if (!class_exists($class)) {
                $this->markTestSkipped($class . ' is not present on disk yet (task T3 has not landed).');
            }
        }
    }

    private function buildFence(): object
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        return new $fenceClass(new $sanitizerClass());
    }

    private function buildSerializer(): object
    {
        $serializerClass = self::SERIALIZER_CLASS;
        return new $serializerClass($this->buildFence());
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    public function testReturnsFencedDetailsWithFamilyAndVariantProducts(): void
    {
        $variantLeft = new Product(productId: 'p-400-l', title: 'Pad Long', price: 79.0, currency: 'USD');
        $variantRegular = new Product(productId: 'p-400-r', title: 'Pad Regular', price: 79.0, currency: 'USD');
        $details = ProductDetails::fromProduct(
            new Product(productId: 'p-400', title: 'Sleeping Pad', price: 79.0, currency: 'USD'),
            'A long description.',
            ['weight' => '1.2kg'],
            [$variantLeft, $variantRegular]
        );
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn($details);

        $handler = new GetProductDetails($backend, $this->buildSerializer(), $this->buildFence());
        $outcome = $handler->handle(
            ['product_id' => 'p-400'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringStartsWith("Product details:\n", $outcome->resultText);
        $this->assertStringContainsString('p-400-l', $outcome->resultText);
        $this->assertCount(3, $outcome->products);
        $this->assertSame('p-400', $outcome->products[0]['product_id']);
        $productIds = array_column($outcome->products, 'product_id');
        $this->assertContains('p-400-l', $productIds);
        $this->assertContains('p-400-r', $productIds);
    }

    public function testMissingProductReturnsAnError(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getProductDetails')->willReturn(null);

        $handler = new GetProductDetails($backend, $this->buildSerializer(), $this->buildFence());
        $outcome = $handler->handle(
            ['product_id' => 'p-404'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertSame('No product with product_id p-404. Search for it by name instead.', $outcome->resultText);
    }
}
