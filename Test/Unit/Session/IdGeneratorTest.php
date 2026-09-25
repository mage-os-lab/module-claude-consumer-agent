<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Session;

use MageOS\AiShoppingAssistant\Model\Session\IdGenerator;
use PHPUnit\Framework\TestCase;

final class IdGeneratorTest extends TestCase
{
    public function testGenerateReturnsSixtyFourHexCharacters(): void
    {
        $generator = new IdGenerator();
        $id = $generator->generate();

        $this->assertSame(64, strlen($id));
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $id));
    }

    public function testGenerateIsUnique(): void
    {
        $generator = new IdGenerator();
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = $generator->generate();
        }

        $this->assertCount(100, array_unique($ids));
    }
}
