<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool;

use MageOS\AiShoppingAssistant\Api\Presentation\PresentationExtensionInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Definition;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\PresentationToolProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PresentationToolProviderTest extends TestCase
{
    private function presentationRegistry(array $extensions = []): PresentationRegistry
    {
        $sanitizer = new Sanitizer();
        $serializer = new Serializer(new Fence($sanitizer));
        return new PresentationRegistry(
            new Products($this->createMock(LoggerInterface::class), $sanitizer),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions($sanitizer),
            $extensions
        );
    }

    private function stubExtension(): PresentationExtensionInterface
    {
        $extension = $this->createMock(PresentationExtensionInterface::class);
        $extension->method('getToolName')->willReturn('present_disclosure');
        $extension->method('getComponent')->willReturn('disclosure');
        $extension->method('getDescription')->willReturn('Show a disclosure card.');
        $extension->method('getInputSchema')->willReturn([
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ]);
        $extension->method('getTemplate')->willReturn('Vendor_Store::cards/disclosure.phtml');
        return $extension;
    }

    public function testExtensionBecomesAPresentationTool(): void
    {
        $provider = new PresentationToolProvider($this->presentationRegistry([$this->stubExtension()]));
        $definitions = $provider->getTools(new AgentConfig());

        $this->assertCount(1, $definitions);
        $definition = $definitions[0];
        $this->assertInstanceOf(Definition::class, $definition);
        $this->assertSame('present_disclosure', $definition->getName());
        $this->assertSame('Show a disclosure card.', $definition->getDescription());
        $this->assertSame('presentation', $definition->getKind());
        $this->assertNull($definition->getHandler());
        $this->assertFalse($definition->takesStatus());
    }

    public function testBuiltInComponentsAreNotDuplicatedAsTools(): void
    {
        $provider = new PresentationToolProvider($this->presentationRegistry([$this->stubExtension()]));
        $names = array_map(
            static fn (Definition $definition): string => $definition->getName(),
            $provider->getTools(new AgentConfig())
        );
        $this->assertNotContains('present_products', $names);
        $this->assertNotContains('present_comparison', $names);
        $this->assertNotContains('present_order_status', $names);
        $this->assertNotContains('checkout', $names);
        $this->assertNotContains('present_suggestions', $names);
    }

    public function testNoExtensionsMeansNoTools(): void
    {
        $provider = new PresentationToolProvider($this->presentationRegistry());
        $this->assertSame([], $provider->getTools(new AgentConfig()));
    }
}
