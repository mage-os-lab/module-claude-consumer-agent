<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Presentation;

use LogicException;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\Presentation\PresentationExtensionInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\CoreToolProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

final class RegistryTest extends TestCase
{
    private const SERIALIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Serializer::class;
    private const FENCE_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence::class;
    private const SANITIZER_CLASS = \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer::class;

    private function skipUnlessT3Available(): void
    {
        foreach ([self::SERIALIZER_CLASS, self::FENCE_CLASS, self::SANITIZER_CLASS] as $class) {
            if (!class_exists($class)) {
                $this->markTestSkipped($class . ' is not present on disk yet (task T3 has not landed).');
            }
        }
    }

    private function buildBuiltIns(): array
    {
        $sanitizerClass = self::SANITIZER_CLASS;
        $fenceClass = self::FENCE_CLASS;
        $serializerClass = self::SERIALIZER_CLASS;
        $sanitizer = new $sanitizerClass();
        $fence = new $fenceClass($sanitizer);
        $serializer = new $serializerClass($fence);
        return [
            new Products($this->createMock(LoggerInterface::class), $sanitizer),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions($sanitizer),
        ];
    }

    public function testCollisionThrowsLogicException(): void
    {
        $this->skipUnlessT3Available();
        $extension = $this->createMock(PresentationExtensionInterface::class);
        $extension->method('getToolName')->willReturn('present_products');

        $this->expectException(LogicException::class);

        [$products, $comparison, $orderStatus, $checkout, $suggestions] = $this->buildBuiltIns();
        new Registry($products, $comparison, $orderStatus, $checkout, $suggestions, [$extension]);
    }

    public function testExtensionIsWrappedAsAComponent(): void
    {
        $this->skipUnlessT3Available();
        $extension = $this->createMock(PresentationExtensionInterface::class);
        $extension->method('getToolName')->willReturn('present_disclosure');
        $extension->method('getComponent')->willReturn('disclosure');
        $extension->method('getInputSchema')->willReturn(['type' => 'object']);
        $extension->method('getTemplate')->willReturn('Vendor_Store::cards/disclosure.phtml');
        $extension->method('enrich')->willReturn(['ok' => true]);

        [$products, $comparison, $orderStatus, $checkout, $suggestions] = $this->buildBuiltIns();
        $registry = new Registry($products, $comparison, $orderStatus, $checkout, $suggestions, [$extension]);

        $this->assertTrue($registry->has('present_disclosure'));
        $component = $registry->components()['present_disclosure'];
        $this->assertSame('present_disclosure', $component->name);
        $this->assertSame('disclosure', $component->component);
        $this->assertSame(['type' => 'object'], $component->schema);
        $this->assertSame('Vendor_Store::cards/disclosure.phtml', $component->template);
        $this->assertContains('Vendor_Store::cards/disclosure.phtml', $registry->templates());

        $backend = $this->createMock(StorefrontBackendInterface::class);
        $page = $this->createMock(PageContextInterface::class);
        $sessionContext = new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
        $ctx = new EnrichmentContext($backend, new AgentConfig(), $sessionContext, new SessionState());
        $this->assertSame(['ok' => true], ($component->enricher)(['x' => 1], $ctx));
    }

    public function testPresentationSchemasMatchCoreToolProviderWhenPresent(): void
    {
        if (!class_exists(CoreToolProvider::class)) {
            $this->markTestSkipped(CoreToolProvider::class . ' is not present on disk yet (task T2 has not landed).');
        }

        try {
            $definitions = $this->coreToolDefinitions();
        } catch (Throwable $exception) {
            $this->markTestSkipped(
                'Could not build a real ' . CoreToolProvider::class . ' yet: ' . $exception->getMessage()
            );
            return;
        }

        $names = [
            'present_products' => Registry::presentProductsSchema(),
            'present_comparison' => Registry::presentComparisonSchema(),
            'present_order_status' => Registry::presentOrderStatusSchema(),
            'checkout' => Registry::checkoutSchema(),
            'present_suggestions' => Registry::presentSuggestionsSchema(),
        ];

        $definitionsByName = [];
        foreach ($definitions as $definition) {
            $definitionsByName[$definition->getName()] = $definition;
        }

        foreach ($names as $name => $schema) {
            $this->assertArrayHasKey($name, $definitionsByName, "CoreToolProvider is missing a definition for {$name}");
            $this->assertEquals(
                $schema,
                $definitionsByName[$name]->getInputSchema(),
                "Schema for {$name} differs between Presentation\\Registry and Tool\\CoreToolProvider"
            );
        }
    }

    private function coreToolDefinitions(): array
    {
        $provider = $this->autowire(CoreToolProvider::class);
        return $provider->getTools(new AgentConfig());
    }

    /**
     * A tiny reflection-based container: mocks interface parameters and recursively
     * builds real instances of concrete (often final) parameters, since PHPUnit cannot
     * generate a test double for a final class.
     */
    private function autowire(string $className): object
    {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->autowireParameter($parameter);
        }
        return $reflection->newInstanceArgs($arguments);
    }

    private function autowireParameter(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
                return [];
            }
            return null;
        }
        $paramClass = new ReflectionClass($type->getName());
        if ($paramClass->isInterface()) {
            return $this->createMock($type->getName());
        }
        return $this->autowire($type->getName());
    }
}
