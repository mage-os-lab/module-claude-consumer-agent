<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

final class SessionState
{
    public const SEEN_PRODUCTS_CAP = 200;

    public const RECENT_CUSTOMER_TEXT_CAP = 8;

    public array $seenProducts;

    public int $turnCounter;

    public array $lastPage;

    public string $surfaceView;

    public array $recentCustomerText;

    public function __construct(
        array $seenProducts = [],
        int $turnCounter = 0,
        array $lastPage = [],
        string $surfaceView = '',
        array $recentCustomerText = []
    ) {
        $this->seenProducts = $seenProducts;
        $this->turnCounter = $turnCounter;
        $this->lastPage = $lastPage;
        $this->surfaceView = $surfaceView;
        $this->recentCustomerText = $recentCustomerText;
    }

    public function rememberProducts(array $records): void
    {
        foreach ($records as $record) {
            $id = (string)$record['product_id'];
            unset($this->seenProducts[$id]);
            $this->seenProducts[$id] = $record;
        }
        while (count($this->seenProducts) > self::SEEN_PRODUCTS_CAP) {
            unset($this->seenProducts[array_key_first($this->seenProducts)]);
        }
    }

    public function rememberCustomerText(string $text): void
    {
        $this->recentCustomerText[] = $text;
        while (count($this->recentCustomerText) > self::RECENT_CUSTOMER_TEXT_CAP) {
            array_shift($this->recentCustomerText);
        }
    }

    public function hasSeen(string $id): bool
    {
        return array_key_exists($id, $this->seenProducts);
    }

    public function seen(string $id): ?array
    {
        return $this->seenProducts[$id] ?? null;
    }

    public function hasSeenCaseInsensitive(string $id): bool
    {
        $needle = strtoupper($id);
        foreach (array_keys($this->seenProducts) as $seenId) {
            if (strtoupper((string)$seenId) === $needle) {
                return true;
            }
        }
        return false;
    }

    public function toJson(): string
    {
        $json = json_encode(
            [
                'seen_products' => $this->seenProducts,
                'turn_counter' => $this->turnCounter,
                'last_page' => $this->lastPage,
                'surface_view' => $this->surfaceView,
                'recent_customer_text' => $this->recentCustomerText,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return $json !== false ? $json : '{}';
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new self();
        }
        $seenProducts = $decoded['seen_products'] ?? [];
        $turnCounter = $decoded['turn_counter'] ?? 0;
        $lastPage = $decoded['last_page'] ?? [];
        $surfaceView = $decoded['surface_view'] ?? '';
        $recentCustomerText = $decoded['recent_customer_text'] ?? [];
        return new self(
            is_array($seenProducts) ? $seenProducts : [],
            is_int($turnCounter) ? $turnCounter : (int)$turnCounter,
            is_array($lastPage) ? $lastPage : [],
            is_string($surfaceView) ? $surfaceView : '',
            is_array($recentCustomerText) ? $recentCustomerText : []
        );
    }
}
