<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class Streaming implements OptionSourceInterface
{
    public const AUTO = 'auto';
    public const OFF = 'off';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTO, 'label' => __('Automatic')],
            ['value' => self::OFF, 'label' => __('Off')],
        ];
    }
}
