<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Skill;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use PHPUnit\Framework\TestCase;

final class LoaderTest extends TestCase
{
    private string $base;
    private string $override;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/aiagent-loader-test-' . uniqid();
        $this->base = $root . '/base';
        $this->override = $root . '/override';
        $this->writeSkill($this->base . '/skill-one', 'skill-one', 'Base version of skill one.', 'Base body.');
        $this->writeSkill($this->base . '/skill-two', 'skill-two', 'Skill two.', 'Skill two body.');
        $this->writeSkill(
            $this->override . '/skill-one',
            'skill-one',
            'Overridden version of skill one.',
            'Override body.'
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(dirname($this->base));
    }

    private function writeSkill(string $dir, string $name, string $description, string $body): void
    {
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/SKILL.md', "---\nname: {$name}\ndescription: {$description}\n---\n{$body}\n");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function buildLoader(array $directories): Loader
    {
        $registrar = $this->createMock(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturnMap([
            [ComponentRegistrar::MODULE, 'Base_Module', $this->base],
            [ComponentRegistrar::MODULE, 'Override_Module', $this->override],
        ]);
        return new Loader($directories, $registrar, new File(), new FrontMatter());
    }

    public function testLoadsEveryDirectoryInSortOrder(): void
    {
        $loader = $this->buildLoader([
            ['module' => 'Base_Module', 'path' => '.', 'sortOrder' => 0],
        ]);
        $names = array_map(static fn ($skill) => $skill->name, $loader->load());
        sort($names);
        $this->assertSame(['skill-one', 'skill-two'], $names);
    }

    public function testALaterDirectoryReplacesASameNamedSkill(): void
    {
        $loader = $this->buildLoader([
            ['module' => 'Base_Module', 'path' => '.', 'sortOrder' => 0],
            ['module' => 'Override_Module', 'path' => '.', 'sortOrder' => 10],
        ]);
        $skills = $loader->load();
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }
        $this->assertCount(2, $skills);
        $this->assertSame('Overridden version of skill one.', $byName['skill-one']->description);
        $this->assertSame('Skill two.', $byName['skill-two']->description);
    }

    public function testSortOrderIsAppliedRegardlessOfArrayOrder(): void
    {
        $loader = $this->buildLoader([
            ['module' => 'Override_Module', 'path' => '.', 'sortOrder' => 10],
            ['module' => 'Base_Module', 'path' => '.', 'sortOrder' => 0],
        ]);
        $skills = $loader->load();
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }
        $this->assertSame('Overridden version of skill one.', $byName['skill-one']->description);
    }

    public function testUnregisteredModuleYieldsNoSkills(): void
    {
        $loader = $this->buildLoader([
            ['module' => 'Missing_Module', 'path' => '.', 'sortOrder' => 0],
        ]);
        $this->assertSame([], $loader->load());
    }
}
