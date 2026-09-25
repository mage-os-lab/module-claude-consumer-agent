<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Fencing;

final class Sanitizer
{
    private const INVISIBLE_PATTERN = '/[\x{00AD}\x{200B}-\x{200F}\x{2028}-\x{2029}\x{202A}-\x{202E}'
        . '\x{2060}-\x{2064}\x{2066}-\x{2069}\x{061C}\x{180E}\x{206A}-\x{206F}\x{FE00}-\x{FE0F}'
        . '\x{FFF9}-\x{FFFB}\x{FEFF}\x{E0000}-\x{E007F}\x{E0100}-\x{E01EF}]/u';

    private const CONTROL_PATTERN = '/[\x{00}-\x{08}\x{0B}\x{0C}\x{0E}-\x{1F}\x{7F}-\x{9F}]/u';

    private const TURN_PATTERN = '/((?:\r\n|\r|\n)[ \t]*(?:\r\n|\r|\n)[ \t]*)(human|assistant|system|user)[ \t]*:/iu';

    private const WHITESPACE_PATTERN = '/\s+/u';

    private const TRUNCATION_SUFFIX = ' ...[truncated]';

    private const TAG_ATTRS = '(?:[ \t]+[\w:.-]{1,40}[ \t]*=[ \t]*(?:"[^"]{0,200}"|\'[^\']{0,200}\'|[^\s"\'>]{1,200})){0,8}';

    public function text(string $s, ?int $maxChars = null, string $label = 'storefront_data'): string
    {
        $valid = $this->utf8($s);
        $normalized = \Normalizer::normalize($valid, \Normalizer::FORM_KC);
        $result = $normalized !== false ? $normalized : $valid;
        $result = $this->replace(self::INVISIBLE_PATTERN, '', $result);
        $result = $this->replace(self::CONTROL_PATTERN, ' ', $result);
        $result = $this->stripMarkersToFixpoint($result, $label);
        $result = $this->replace(self::TURN_PATTERN, '$1$2 -', $result);
        if ($result === null) {
            return '';
        }
        return $this->truncate($result, $maxChars);
    }

    public function label(string $s, int $maxChars): string
    {
        $line = $this->replace(self::INVISIBLE_PATTERN, '', $this->utf8($s));
        $line = $this->replace(self::CONTROL_PATTERN, ' ', $line);
        $line = $this->replace(self::WHITESPACE_PATTERN, ' ', $line);
        if ($line === null) {
            return '';
        }
        $line = trim($line);
        if (mb_strlen($line) > $maxChars) {
            $line = rtrim(mb_substr($line, 0, $maxChars - 1)) . "\u{2026}";
        }
        return $line;
    }

    public function chips(array $list, int $maxChips = 4, int $maxChars = 80): array
    {
        $cleaned = [];
        foreach ($list as $chip) {
            $label = $this->label((string)$chip, $maxChars);
            if ($label !== '') {
                $cleaned[] = $label;
            }
            if (count($cleaned) === $maxChips) {
                break;
            }
        }
        return $cleaned;
    }

    public function value(mixed $v, int $maxChars = 1000): mixed
    {
        if (is_string($v)) {
            return $this->text($v, $maxChars);
        }
        if (is_array($v)) {
            return $this->valueArray($v, $maxChars);
        }
        return $v;
    }

    private function valueArray(array $v, int $maxChars): array
    {
        if (array_is_list($v)) {
            return array_map(fn (mixed $item): mixed => $this->value($item, $maxChars), $v);
        }
        $result = [];
        foreach ($v as $key => $item) {
            $result[$this->text((string)$key, 200)] = $this->value($item, $maxChars);
        }
        return $result;
    }

    private function stripMarkersToFixpoint(?string $text, string $label): ?string
    {
        if ($text === null) {
            return null;
        }
        $markerPattern = '/<\s*\/?\s*' . preg_quote($label, '/') . '(?![A-Za-z0-9_])(?:[^<>]*>)?/iu';
        $specialTokenPattern = '/<[ \t]*\/?[ \t]*(?:(?:[a-z][\w.-]{0,30}:)?(?:transcript|conversation'
            . '|function_calls|function_results|invoke|tool_use|tool_result|system|human|user|assistant)'
            . '|[a-z][\w.-]{0,30}:(?:parameter|result))\b' . self::TAG_ATTRS
            . '[ \t]*\/?>|<\|[^|<>\r\n]{1,64}\|>/iu';
        while (true) {
            $withoutMarker = $this->replace($markerPattern, '[removed]', $text);
            $stripped = $this->replace($specialTokenPattern, '[removed]', $withoutMarker);
            if ($stripped === null) {
                return null;
            }
            if ($stripped === $text) {
                break;
            }
            $text = $stripped;
        }
        return $text;
    }

    private function replace(string $pattern, string $replacement, ?string $subject): ?string
    {
        if ($subject === null) {
            return null;
        }
        return preg_replace($pattern, $replacement, $subject);
    }

    private function utf8(string $s): string
    {
        if (mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $converted = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        return is_string($converted) ? $converted : '';
    }

    private function truncate(string $s, ?int $maxChars): string
    {
        if ($maxChars === null || mb_strlen($s) <= $maxChars) {
            return $s;
        }
        $suffixLength = mb_strlen(self::TRUNCATION_SUFFIX);
        if ($maxChars > $suffixLength) {
            return mb_substr($s, 0, $maxChars - $suffixLength) . self::TRUNCATION_SUFFIX;
        }
        return mb_substr($s, 0, $maxChars);
    }
}
