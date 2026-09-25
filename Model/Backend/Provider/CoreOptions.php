<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\AiShoppingAssistant\Api\Backend\ProductOptionsProviderInterface;

final class CoreOptions implements ProductOptionsProviderInterface
{
    public function summarize(ProductInterface $product): array
    {
        $options = [];
        $required = false;
        $customOptions = [];

        foreach ((array)$product->getOptions() as $option) {
            if ($option->getIsRequire()) {
                $required = true;
            }
            $title = (string)$option->getTitle();
            if ($title === '') {
                continue;
            }
            $options[$title] = count((array)$option->getValues());
            $customOptions[] = [
                'option_id' => (int)$option->getOptionId(),
                'title' => $title,
                'type' => (string)$option->getType(),
                'required' => (bool)$option->getIsRequire(),
                'values' => $this->values($option),
            ];
        }

        return [
            'options' => $options,
            'required' => $required,
            'titles' => array_keys($options),
            'custom_options' => $customOptions,
        ];
    }

    private function values(ProductCustomOptionInterface $option): array
    {
        $values = [];
        foreach ((array)$option->getValues() as $value) {
            if (!$value instanceof ProductCustomOptionValuesInterface) {
                continue;
            }
            $values[] = [
                'value_id' => (int)$value->getOptionTypeId(),
                'title' => (string)$value->getTitle(),
                'price' => (float)$value->getPrice(),
                'price_type' => (string)$value->getPriceType(),
            ];
        }
        return $values;
    }
}
