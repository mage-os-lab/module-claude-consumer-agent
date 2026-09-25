<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Skill;

/**
 * Reads the SKILL.md frontmatter dialect: a "---" fenced block of "key: value" lines,
 * a value may continue on indented lines, before the trimmed body.
 */
final class FrontMatter
{
    public function parse(string $text, string $path): Skill
    {
        if (!str_starts_with($text, '---')) {
            throw new \RuntimeException($path . ': missing frontmatter');
        }
        $secondMarker = strpos($text, '---', 3);
        if ($secondMarker === false) {
            throw new \RuntimeException($path . ': malformed frontmatter fences');
        }
        $frontMatter = substr($text, 3, $secondMarker - 3);
        $body = trim(substr($text, $secondMarker + 3));
        $fields = $this->readFields($frontMatter);
        $name = $fields['name'] ?? '';
        $description = $fields['description'] ?? '';
        if ($name === '' || $description === '') {
            throw new \RuntimeException($path . ': frontmatter needs `name` and `description`');
        }
        return new Skill($name, $description, $body);
    }

    private function readFields(string $frontMatter): array
    {
        $fields = [];
        $currentKey = null;
        foreach (explode("\n", $frontMatter) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/', $line, $matches) === 1) {
                $currentKey = $matches[1];
                $fields[$currentKey] = trim($matches[2]);
                continue;
            }
            if ($currentKey !== null && preg_match('/^\s/', $line) === 1) {
                $fields[$currentKey] = trim($fields[$currentKey] . ' ' . trim($line));
            }
        }
        foreach ($fields as $key => $value) {
            $fields[$key] = $this->stripQuotes($value);
        }
        return $fields;
    }

    private function stripQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length < 2) {
            return $value;
        }
        $first = $value[0];
        $last = $value[$length - 1];
        $isDoubleQuoted = $first === '"' && $last === '"';
        $isSingleQuoted = $first === '\'' && $last === '\'';
        if ($isDoubleQuoted || $isSingleQuoted) {
            return substr($value, 1, -1);
        }
        return $value;
    }
}
