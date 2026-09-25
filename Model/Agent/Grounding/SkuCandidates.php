<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Grounding;

/**
 * Pulls the tokens of a customer message that could be a SKU: whitespace-separated, edge
 * punctuation stripped, at least one digit, three to sixty-four characters. The catalog
 * decides which of them exist, this class only keeps the list short and free of plain words.
 */
final class SkuCandidates
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 64;
    private const MAX_CANDIDATES = 8;
    private const EDGE_TRIM = '/^[^\p{L}\p{N}\-_.\/]+|[^\p{L}\p{N}\-_\/]+$/u';

    public function fromText(string $text): array
    {
        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }
        $candidates = [];
        $seen = [];
        foreach ($tokens as $token) {
            $candidate = (string)preg_replace(self::EDGE_TRIM, '', $token);
            if (!$this->qualifies($candidate)) {
                continue;
            }
            $key = mb_strtolower($candidate);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidates[] = $candidate;
            if (count($candidates) === self::MAX_CANDIDATES) {
                break;
            }
        }
        return $candidates;
    }

    private function qualifies(string $candidate): bool
    {
        $length = mb_strlen($candidate);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            return false;
        }
        return preg_match('/\p{N}/u', $candidate) === 1;
    }
}
