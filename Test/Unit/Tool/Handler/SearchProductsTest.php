<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\SearchProducts;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use PHPUnit\Framework\TestCase;

final class SearchProductsTest extends TestCase
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

    private function buildSerializer(): object
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        $serializerClass = self::SERIALIZER_CLASS;
        $fence = new $fenceClass(new $sanitizerClass());
        return new $serializerClass($fence);
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    public function testSearchResultsAreFencedAndReturnedAsProducts(): void
    {
        $product = new Product(productId: 'p-100', title: 'Tent', price: 149.0, currency: 'USD');
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchProducts')
            ->with($this->anything(), 'tent', $this->anything(), 8)
            ->willReturn([$product]);

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $outcome = $handler->handle(
            ['query' => 'tent'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringContainsString('Search returned 1 result(s):', $outcome->resultText);
        $this->assertStringContainsString('p-100', $outcome->resultText);
        $this->assertSame([$product->toArray()], $outcome->products);
    }

    public function testEmptySearchResultUsesTheNoMatchHeader(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('searchProducts')->willReturn([]);

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $outcome = $handler->handle(
            ['query' => 'AR-1602'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
        $this->assertStringStartsWith('Search returned 0 results:', $outcome->resultText);
        $this->assertSame([], $outcome->products);
    }

    public function testLimitIsClampedToConfiguredMaxSearchResults(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchProducts')
            ->with($this->anything(), 'tent', $this->anything(), 8)
            ->willReturn([]);

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $handler->handle(
            ['query' => 'tent', 'limit' => 25],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
    }

    public function testMissingQueryAndCategoryIdReturnsAnErrorOutcome(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('searchProducts');

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $outcome = $handler->handle(
            [],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertTrue($outcome->isError);
        $this->assertSame('Give a query, or filters.category_id to list a category.', $outcome->resultText);
    }

    public function testEmptyQueryWithCategoryIdFilterIsAllowed(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchProducts')
            ->with($this->anything(), '', $this->anything(), 8)
            ->willReturn([]);

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $outcome = $handler->handle(
            ['filters' => ['category_id' => 175]],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );

        $this->assertFalse($outcome->isError);
    }

    public function testLimitIsFlooredAtOneWhenRequestedLimitIsZeroOrLess(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->once())
            ->method('searchProducts')
            ->with($this->anything(), 'tent', $this->anything(), 1)
            ->willReturn([]);

        $handler = new SearchProducts($backend, $this->buildSerializer());
        $handler->handle(
            ['query' => 'tent', 'limit' => -5],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
    }
}
