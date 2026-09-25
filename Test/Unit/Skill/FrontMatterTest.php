<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Skill;

use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use PHPUnit\Framework\TestCase;

final class FrontMatterTest extends TestCase
{
    private function skillsDir(): string
    {
        return dirname(__DIR__, 3) . '/skills';
    }

    public function testParseExtractsFrontmatterAndBody(): void
    {
        $text = "---\nname: search-discovery\ndescription: Finding and choosing products.\n---\n\n"
            . "# Search and discovery\n\nGround every pick in search results.\n";
        $skill = (new FrontMatter())->parse($text, 'SKILL.md');
        $this->assertSame('search-discovery', $skill->name);
        $this->assertStringStartsWith('Finding and choosing', $skill->description);
        $this->assertStringStartsWith('# Search and discovery', $skill->body);
    }

    public function testMissingFrontmatterThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FrontMatter())->parse('# no frontmatter here', 'SKILL.md');
    }

    public function testMissingDescriptionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FrontMatter())->parse("---\nname: only-name\n---\nbody", 'SKILL.md');
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FrontMatter())->parse("---\ndescription: only a description\n---\nbody", 'SKILL.md');
    }

    public function testTheFiveShippedSkillFilesParse(): void
    {
        $frontMatter = new FrontMatter();
        $expected = [
            'customer-care',
            'memory-personalization',
            'planning-goals',
            'purchase-research',
            'search-discovery',
        ];
        foreach ($expected as $name) {
            $path = $this->skillsDir() . '/' . $name . '/SKILL.md';
            $this->assertFileExists($path);
            $text = file_get_contents($path);
            $this->assertIsString($text);
            $skill = $frontMatter->parse($text, $path);
            $this->assertSame($name, $skill->name);
            $this->assertNotSame('', $skill->description);
            $this->assertNotSame('', $skill->body);
        }
    }
}
