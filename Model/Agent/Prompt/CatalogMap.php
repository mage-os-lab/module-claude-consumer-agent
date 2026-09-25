<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

/**
 * Pure formatter: turns the node tree from CatalogMapProviderInterface::map() into the
 * prompt's catalog map text. The tree's own nesting (children, grandchildren) carries the
 * configured depth, so this class infers depth from shape rather than taking it as an
 * argument.
 */
final class CatalogMap
{
    private const TRUNCATION_LINE = '- ... (map truncated; use search_categories for the rest)';

    public function text(array $nodes, int $maxChars): string
    {
        $lines = [];
        foreach ($nodes as $node) {
            $lines = array_merge($lines, $this->linesForRoot(is_array($node) ? $node : []));
        }
        return $this->applyLimit($lines, $maxChars);
    }

    private function linesForRoot(array $node): array
    {
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        if ($children === []) {
            return ['- ' . $this->bareLabel($node)];
        }

        $childLabels = array_map(fn (array $child): string => $this->bareLabel($child), $children);
        $lines = ['- ' . $this->bareLabel($node) . ': ' . implode(', ', $childLabels)];

        foreach ($children as $child) {
            $grandchildren = is_array($child['children'] ?? null) ? $child['children'] : [];
            if ($grandchildren === []) {
                continue;
            }
            $grandchildLabels = array_map(fn (array $g): string => $this->bareLabel($g), $grandchildren);
            $lines[] = '  - ' . $this->bareLabel($child) . ': ' . implode(', ', $grandchildLabels);
        }

        return $lines;
    }

    private function bareLabel(array $node): string
    {
        return (string)($node['name'] ?? '') . ' [' . (int)($node['id'] ?? 0) . ']';
    }

    private function applyLimit(array $lines, int $maxChars): string
    {
        $text = implode("\n", $lines);
        if ($maxChars <= 0 || mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $budget = $maxChars - mb_strlen(self::TRUNCATION_LINE) - 1;
        $kept = [];
        $length = 0;
        foreach ($lines as $line) {
            $addedLength = $kept === [] ? mb_strlen($line) : $length + 1 + mb_strlen($line);
            if ($addedLength > $budget) {
                break;
            }
            $kept[] = $line;
            $length = $addedLength;
        }
        $kept[] = self::TRUNCATION_LINE;

        return implode("\n", $kept);
    }
}
