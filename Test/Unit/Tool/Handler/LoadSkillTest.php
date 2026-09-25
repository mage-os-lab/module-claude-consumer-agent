<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Tool\Handler;

use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Filesystem\Driver\File;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler\LoadSkill;
use PHPUnit\Framework\TestCase;

final class LoadSkillTest extends TestCase
{
    private string $modulePath;

    protected function setUp(): void
    {
        $this->modulePath = sys_get_temp_dir() . '/aiagent_skill_test_' . uniqid('', true);
        mkdir($this->modulePath . '/skills/search-discovery', 0777, true);
        file_put_contents(
            $this->modulePath . '/skills/search-discovery/SKILL.md',
            "---\nname: search-discovery\ndescription: How to search the catalog.\n---\nGround every pick in search results."
        );
    }

    protected function tearDown(): void
    {
        $skillFile = $this->modulePath . '/skills/search-discovery/SKILL.md';
        if (is_file($skillFile)) {
            unlink($skillFile);
        }
        @rmdir($this->modulePath . '/skills/search-discovery');
        @rmdir($this->modulePath . '/skills');
        @rmdir($this->modulePath);
    }

    private function buildRegistry(): Registry
    {
        $componentRegistrar = $this->createMock(ComponentRegistrarInterface::class);
        $componentRegistrar->method('getPath')->willReturn($this->modulePath);
        $loader = new Loader(
            [['module' => 'MageOS_AiShoppingAssistant', 'path' => 'skills', 'sortOrder' => 0]],
            $componentRegistrar,
            new File(),
            new FrontMatter()
        );
        return new Registry($loader);
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    public function testReturnsSkillBodyWhenFound(): void
    {
        $handler = new LoadSkill($this->buildRegistry());
        $outcome = $handler->handle(
            ['skill_name' => 'search-discovery'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
        $this->assertFalse($outcome->isError);
        $this->assertSame('Ground every pick in search results.', $outcome->resultText);
    }

    public function testUnknownSkillListsAvailableNames(): void
    {
        $handler = new LoadSkill($this->buildRegistry());
        $outcome = $handler->handle(
            ['skill_name' => 'made-up'],
            $this->context(),
            new SessionState(),
            new AgentConfig()
        );
        $this->assertTrue($outcome->isError);
        $this->assertSame('No skill named made-up. Available: search-discovery.', $outcome->resultText);
    }
}
