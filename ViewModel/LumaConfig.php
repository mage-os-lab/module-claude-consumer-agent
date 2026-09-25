<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

final class LumaConfig implements ArgumentInterface
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE;
    private const STARTER_LIMIT = 5;
    private const CONTACT_URL_PATTERN = '#^(https?://|/(?![/\\\\])|mailto:|tel:)#i';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\ViewModel\Assistant $assistant,
        private readonly \MageOS\AiShoppingAssistant\ViewModel\CartSection $cartSection,
        private readonly \Magento\Framework\Locale\FormatInterface $localeFormat
    ) {
    }

    public function config(): array
    {
        $snapshot = $this->assistant->snapshot();
        $contact = $snapshot['contact'];
        return array_merge($snapshot, [
            'starters' => array_slice($snapshot['starters'], 0, self::STARTER_LIMIT),
            'contact' => [
                'url' => preg_match(self::CONTACT_URL_PATTERN, $contact['url']) === 1 ? $contact['url'] : '',
                'label' => $contact['label'],
            ],
            'priceFormat' => $this->localeFormat->getPriceFormat(),
            'cartUrl' => $this->cartSection->cartUrl(),
        ]);
    }

    public function json(): string
    {
        return (string)json_encode($this->config(), self::JSON_FLAGS);
    }
}
