<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use MageOS\AiShoppingAssistant\Model\Client\ApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testToStringReturnsTheWrappedValue(): void
    {
        $apiKey = new ApiKey('sk-ant-test-value');

        $this->assertSame('sk-ant-test-value', (string)$apiKey);
    }

    public function testDebugInfoMasksTheValue(): void
    {
        $apiKey = new ApiKey('sk-ant-test-value');

        $this->assertSame(['value' => '***'], $apiKey->__debugInfo());
    }

    public function testPrintRDoesNotLeakTheValue(): void
    {
        $apiKey = new ApiKey('sk-ant-test-value');

        $dump = print_r($apiKey, true);

        $this->assertStringNotContainsString('sk-ant-test-value', $dump);
        $this->assertStringContainsString('***', $dump);
    }
}
