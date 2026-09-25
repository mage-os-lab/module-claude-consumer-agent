<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

/**
 * Not final: Magento generates an interceptor for config backend models.
 */
class Lines extends Value
{
    public function beforeSave()
    {
        $value = (string)$this->getValue();
        $lines = explode("\n", str_replace("\r\n", "\n", $value));
        $trimmed = array_map('trim', $lines);
        $filtered = array_filter($trimmed, static fn (string $line): bool => $line !== '');
        $unique = array_values(array_unique($filtered));
        $this->setValue(implode("\n", $unique));
        return parent::beforeSave();
    }
}
