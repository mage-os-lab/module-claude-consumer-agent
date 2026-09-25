<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Block\Adminhtml\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Framework\View\LayoutInterface;
use MageOS\AiShoppingAssistant\Block\Adminhtml\Form\Field\StoreFacts;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class StoreFactsTest extends TestCase
{
    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            fn (string $class) => $this->createMock($class)
        );
        ObjectManager::setInstance($objectManager);
    }

    private function buildBlock(): StoreFacts
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('createBlock')->willReturnCallback(
            fn (string $class) => $this->createMock($class)
        );

        $context = $this->createMock(Context::class);
        $context->method('getLayout')->willReturn($layout);

        return new StoreFacts($context, [], $this->createMock(SecureHtmlRenderer::class));
    }

    public function testPrepareToRenderRegistersFourColumns(): void
    {
        $block = $this->buildBlock();
        $method = new ReflectionMethod(StoreFacts::class, '_prepareToRender');
        $method->invoke($block);

        $columns = $block->getColumns();

        $this->assertCount(4, $columns);
        $this->assertSame(['topic', 'keywords', 'source', 'value'], array_keys($columns));
    }
}
