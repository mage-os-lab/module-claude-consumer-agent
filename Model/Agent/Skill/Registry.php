<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Skill;

final class Registry
{
    private ?array $cachedSkills = null;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader $loader
    ) {
    }

    public function names(): array
    {
        return array_map(static fn (Skill $skill): string => $skill->name, $this->skills());
    }

    public function indexBlock(): string
    {
        $skills = $this->skills();
        if ($skills === []) {
            return '(no skills installed)';
        }
        $lines = array_map(
            static fn (Skill $skill): string => sprintf('- `%s` : %s', $skill->name, $skill->description),
            $skills
        );
        return implode("\n", $lines);
    }

    public function body(string $name): ?string
    {
        foreach ($this->skills() as $skill) {
            if ($skill->name === $name) {
                return $skill->body;
            }
        }
        return null;
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->skills() as $skill) {
            $parts[] = $skill->name . ':' . $skill->description . ':' . sha1($skill->body);
        }
        sort($parts);
        return sha1(implode("\n", $parts));
    }

    private function skills(): array
    {
        if ($this->cachedSkills === null) {
            $skills = $this->loader->load();
            usort($skills, static fn (Skill $a, Skill $b): int => $a->name <=> $b->name);
            $this->cachedSkills = $skills;
        }
        return $this->cachedSkills;
    }
}
