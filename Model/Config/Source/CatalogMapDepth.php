<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class CatalogMapDepth implements OptionSourceInterface
{
    public const OFF = 0;
    public const ONE = 1;
    public const TWO = 2;
    public const THREE = 3;

    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('Off')],
            ['value' => self::ONE, 'label' => __('1')],
            ['value' => self::TWO, 'label' => __('2')],
            ['value' => self::THREE, 'label' => __('3')],
        ];
    }
}
