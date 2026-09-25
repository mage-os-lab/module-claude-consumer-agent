<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

final class ThinkingEffort implements OptionSourceInterface
{
    public const OFF = 'off';
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFF, 'label' => __('Off')],
            ['value' => self::LOW, 'label' => __('Low')],
            ['value' => self::MEDIUM, 'label' => __('Medium')],
            ['value' => self::HIGH, 'label' => __('High')],
        ];
    }
}
