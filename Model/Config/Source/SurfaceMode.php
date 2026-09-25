<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class SurfaceMode implements OptionSourceInterface
{
    public const AUTO = 'auto';
    public const SIDE_CART = 'side_cart';
    public const OVERLAY = 'overlay';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::SIDE_CART, 'label' => __('Cart')],
            ['value' => self::OVERLAY, 'label' => __('Overlay')],
        ];
    }
}
