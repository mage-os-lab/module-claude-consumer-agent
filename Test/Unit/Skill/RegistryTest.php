<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Skill;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Filesystem\Driver\File;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private function realRegistry(): Registry
    {
        $loader = new Loader(
            [['module' => 'MageOS_AiShoppingAssistant', 'path' => 'skills', 'sortOrder' => 0]],
            new ComponentRegistrar(),
            new File(),
            new FrontMatter()
        );
        return new Registry($loader);
    }

    public function testNamesAreSorted(): void
    {
        $names = $this->realRegistry()->names();
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertContains('search-discovery', $names);
    }

    public function testIndexBlockLinesUseAHyphenNotADashCharacter(): void
    {
        $lines = explode("\n", $this->realRegistry()->indexBlock());
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('- `', $line);
            $this->assertStringNotContainsString("\xE2\x80\x94", $line);
            $this->assertStringNotContainsString("\xE2\x80\x93", $line);
            $this->assertMatchesRegularExpression('/^- `[^`]+` : .+$/', $line);
        }
    }

    public function testBodyReturnsSkillTextOrNull(): void
    {
        $registry = $this->realRegistry();
        $this->assertNotNull($registry->body('search-discovery'));
        $this->assertNull($registry->body('does-not-exist'));
    }

    public function testFingerprintIsStableAcrossCalls(): void
    {
        $registry = $this->realRegistry();
        $this->assertSame($registry->fingerprint(), $registry->fingerprint());
    }
}
