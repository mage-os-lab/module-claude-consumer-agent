<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\AiShoppingAssistant\Observer\HyvaConfigGenerateBefore;
use PHPUnit\Framework\TestCase;

final class HyvaConfigGenerateBeforeTest extends TestCase
{
    private function buildObserver(DataObject $config): Observer
    {
        $event = new Event(['config' => $config]);
        $observer = new Observer();
        $observer->setEvent($event);
        return $observer;
    }

    public function testExecuteAddsExtensionEntryForAPathInsideBp(): void
    {
        $modulePath = BP . '/app/code/MageOS/AiShoppingAssistant';
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->expects($this->once())
            ->method('getPath')
            ->with(ComponentRegistrar::MODULE, 'MageOS_AiShoppingAssistant')
            ->willReturn($modulePath);
        $config = new DataObject();

        $observerClass = new HyvaConfigGenerateBefore($registrar);
        $observerClass->execute($this->buildObserver($config));

        $this->assertSame(
            [['src' => 'app/code/MageOS/AiShoppingAssistant']],
            $config->getData('extensions')
        );
    }

    public function testExecuteAppendsToExistingExtensions(): void
    {
        $modulePath = BP . '/app/code/MageOS/AiShoppingAssistant';
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturn($modulePath);
        $config = new DataObject(['extensions' => [['src' => 'app/code/Other/Module']]]);

        $observerClass = new HyvaConfigGenerateBefore($registrar);
        $observerClass->execute($this->buildObserver($config));

        $this->assertSame(
            [
                ['src' => 'app/code/Other/Module'],
                ['src' => 'app/code/MageOS/AiShoppingAssistant'],
            ],
            $config->getData('extensions')
        );
    }

    public function testExecuteSkipsWhenPathIsOutsideBp(): void
    {
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturn('/somewhere/else/entirely');
        $config = new DataObject();

        $observerClass = new HyvaConfigGenerateBefore($registrar);
        $observerClass->execute($this->buildObserver($config));

        $this->assertFalse($config->hasData('extensions'));
    }
}
