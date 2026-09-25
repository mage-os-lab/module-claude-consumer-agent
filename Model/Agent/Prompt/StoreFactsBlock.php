<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Prompt;

/**
 * Pure formatter: turns the resolved fact list from StoreConfig plus the CoreFacts pool's
 * lines into the prompt's Store facts text. Title resolution for cms_page and cms_block
 * rows happens before this class is called; a fact missing a resolved title falls back to
 * its own identifier.
 */
final class StoreFactsBlock
{
    private const HEADER = "# Store facts\n\nServices and terms this store offers, with the words "
        . "customers use for them. Answer a service question from this list or from a "
        . "search_policies result. A service that is not listed here is not offered: say so "
        . "in one sentence, without apology, and never offer it as a chip. A row that carries "
        . "its answer here is complete: answer from it without a tool call. A row that points "
        . "at a page or block needs search_policies. A question about a service is not an "
        . "order lookup.";

    private const TRUNCATION_LINE = '- ... (list truncated)';

    public function text(array $facts, array $coreLines, int $maxChars): string
    {
        $lines = [];
        $notOffered = [];

        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $source = (string)($fact['source'] ?? 'text');
            if ($source === 'not_offered') {
                $topic = trim((string)($fact['topic'] ?? ''));
                if ($topic !== '') {
                    $notOffered[] = mb_strtolower($topic);
                }
                continue;
            }
            $lines[] = $this->factLine($fact, $source);
        }

        foreach ($coreLines as $coreLine) {
            if (is_string($coreLine) && $coreLine !== '') {
                $lines[] = '- ' . $coreLine;
            }
        }

        if ($notOffered !== []) {
            $lines[] = '- Not offered here: ' . implode(', ', $notOffered) . '.';
        }

        if ($lines === []) {
            return '';
        }

        return $this->applyLimit($lines, $maxChars);
    }

    private function factLine(array $fact, string $source): string
    {
        $topic = (string)($fact['topic'] ?? '');
        $keywords = is_array($fact['keywords'] ?? null) ? $fact['keywords'] : [];
        $keywordsText = $keywords !== [] ? ' (' . implode(', ', $keywords) . ')' : '';

        return match ($source) {
            'cms_page' => '- ' . $topic . $keywordsText . ': covered by the page "' . $this->title($fact)
                . '"; call search_policies with these words for the terms.',
            'cms_block' => '- ' . $topic . $keywordsText . ': covered by the block "' . $this->title($fact)
                . '"; call search_policies with these words for the terms.',
            default => '- ' . $topic . $keywordsText . ': ' . (string)($fact['value'] ?? ''),
        };
    }

    private function title(array $fact): string
    {
        $title = trim((string)($fact['title'] ?? ''));
        return $title !== '' ? $title : (string)($fact['value'] ?? '');
    }

    private function applyLimit(array $lines, int $maxChars): string
    {
        $header = self::HEADER;
        $full = $header . "\n\n" . implode("\n", $lines);
        if ($maxChars <= 0 || mb_strlen($full) <= $maxChars) {
            return $full;
        }

        $budget = $maxChars - mb_strlen($header) - 2 - mb_strlen(self::TRUNCATION_LINE) - 1;
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

        return $header . "\n\n" . implode("\n", $kept);
    }
}
