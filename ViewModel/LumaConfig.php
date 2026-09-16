<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

final class LumaConfig implements ArgumentInterface
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE;
    private const STARTER_LIMIT = 5;

    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\ViewModel\Assistant $assistant,
        private readonly \MageOS\ClaudeConsumerAgent\ViewModel\CartSection $cartSection,
        private readonly \Magento\Framework\Locale\FormatInterface $localeFormat
    ) {
    }

    public function config(): array
    {
        $snapshot = $this->assistant->snapshot();
        return array_merge($snapshot, [
            'starters' => array_slice($snapshot['starters'], 0, self::STARTER_LIMIT),
            'priceFormat' => $this->localeFormat->getPriceFormat(),
            'cartUrl' => $this->cartSection->cartUrl(),
        ]);
    }

    public function json(): string
    {
        return (string)json_encode($this->config(), self::JSON_FLAGS);
    }
}
