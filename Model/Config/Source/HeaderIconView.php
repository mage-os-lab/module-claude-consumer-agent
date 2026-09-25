<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class HeaderIconView implements OptionSourceInterface
{
    public const CART = 'cart';
    public const CHAT = 'chat';
    public const LAST = 'last';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::CART, 'label' => __('Cart')],
            ['value' => self::CHAT, 'label' => __('Chat')],
            ['value' => self::LAST, 'label' => __('Last Used')],
        ];
    }
}
