<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Request;

use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;

final class BodyReader
{
    private const SESSION_ID_PATTERN = '/^[0-9a-f]{64}$/';

    private const ID_PATTERN = '/^[0-9]{1,20}$/';

    private const PAGE_ID_KEYS = ['product_id', 'category_id'];

    private const PAGE_TEXT_LIMITS = ['query' => 200, 'product_name' => 120, 'category_name' => 120];

    public function read(\Magento\Framework\App\RequestInterface $request, AgentConfig $config): TurnRequest
    {
        $data = $this->decode($request);
        $message = trim((string)($data['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > $config->maxMessageLength) {
            throw new \InvalidArgumentException('message is required and must not exceed the configured length');
        }
        $page = $this->normalizePage($data['page'] ?? null);
        $stream = ($data['stream'] ?? 1) == 1;
        return new TurnRequest($this->normalizeSessionId($data['session'] ?? null), $message, $page, $stream);
    }

    public function readStart(\Magento\Framework\App\RequestInterface $request): TurnRequest
    {
        $data = $this->decode($request);
        $page = $this->normalizePage($data['page'] ?? null);
        return new TurnRequest($this->normalizeSessionId($data['session'] ?? null), '', $page, true);
    }

    private function decode(\Magento\Framework\App\RequestInterface $request): array
    {
        $raw = (string)$request->getContent();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('request body must be a JSON object');
        }
        return $data;
    }

    private function normalizeSessionId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return preg_match(self::SESSION_ID_PATTERN, $value) === 1 ? $value : null;
    }

    private function normalizePage(mixed $page): array
    {
        if (!is_array($page)) {
            return [];
        }
        $normalized = [];
        foreach ($page as $key => $value) {
            if ($key === 'page_type') {
                $normalized[$key] = is_string($value) ? $value : null;
                continue;
            }
            if (in_array($key, self::PAGE_ID_KEYS, true)) {
                $normalized[$key] = $this->normalizeId($value);
                continue;
            }
            if (isset(self::PAGE_TEXT_LIMITS[$key])) {
                $normalized[$key] = $this->normalizeText($value, self::PAGE_TEXT_LIMITS[$key]);
            }
        }
        return $normalized;
    }

    private function normalizeId(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string)$value;
        }
        if (!is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            return null;
        }
        return $value;
    }

    private function normalizeText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return mb_substr($trimmed, 0, $maxLength);
    }
}
