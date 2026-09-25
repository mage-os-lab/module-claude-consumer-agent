<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Skill;

use Magento\Framework\Component\ComponentRegistrar;

final class Loader
{
    public function __construct(
        private readonly array $directories,
        private readonly \Magento\Framework\Component\ComponentRegistrarInterface $componentRegistrar,
        private readonly \Magento\Framework\Filesystem\Driver\File $fileDriver,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter $frontMatter
    ) {
    }

    public function load(): array
    {
        $directories = $this->directories;
        usort(
            $directories,
            static fn (array $a, array $b): int => ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0)
        );
        $skills = [];
        foreach ($directories as $directory) {
            foreach ($this->loadDirectory($directory) as $skill) {
                $skills[$skill->name] = $skill;
            }
        }
        return array_values($skills);
    }

    private function loadDirectory(array $directory): array
    {
        $modulePath = $this->componentRegistrar->getPath(
            ComponentRegistrar::MODULE,
            (string)$directory['module']
        );
        if ($modulePath === null) {
            return [];
        }
        $root = rtrim($modulePath, '/') . '/' . trim((string)$directory['path'], '/');
        if (!$this->fileDriver->isDirectory($root)) {
            return [];
        }
        $skills = [];
        foreach ($this->fileDriver->readDirectory($root) as $entry) {
            if (!$this->fileDriver->isDirectory($entry)) {
                continue;
            }
            $skillFile = rtrim($entry, '/') . '/SKILL.md';
            if (!$this->fileDriver->isExists($skillFile) || !$this->fileDriver->isFile($skillFile)) {
                continue;
            }
            $text = $this->fileDriver->fileGetContents($skillFile);
            $skills[] = $this->frontMatter->parse($text, $skillFile);
        }
        return $skills;
    }
}
