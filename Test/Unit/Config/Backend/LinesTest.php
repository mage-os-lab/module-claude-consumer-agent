<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use MageOS\AiShoppingAssistant\Model\Config\Backend\Lines;
use PHPUnit\Framework\TestCase;

final class LinesTest extends TestCase
{
    private function buildLines(): Lines
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($eventManager);
        $registry = $this->createMock(Registry::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        return new Lines($context, $registry, $scopeConfig, $cacheTypeList);
    }

    public function testBeforeSaveTrimsAndDropsEmptyLines(): void
    {
        $lines = $this->buildLines();
        $lines->setValue("  Find a gift  \n\n  What is new?  \n");
        $lines->beforeSave();
        $this->assertSame("Find a gift\nWhat is new?", $lines->getValue());
    }

    public function testBeforeSaveDropsDuplicateLines(): void
    {
        $lines = $this->buildLines();
        $lines->setValue("return\nreturns\nreturn\nreturns");
        $lines->beforeSave();
        $this->assertSame("return\nreturns", $lines->getValue());
    }

    public function testBeforeSaveHandlesCarriageReturns(): void
    {
        $lines = $this->buildLines();
        $lines->setValue("one\r\ntwo\r\none");
        $lines->beforeSave();
        $this->assertSame("one\ntwo", $lines->getValue());
    }

    public function testBeforeSaveEmptyValueYieldsEmptyString(): void
    {
        $lines = $this->buildLines();
        $lines->setValue('');
        $lines->beforeSave();
        $this->assertSame('', $lines->getValue());
    }
}
