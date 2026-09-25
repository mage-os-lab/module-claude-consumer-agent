<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block\Adminhtml\Form\Field;

use Magento\Framework\View\Element\AbstractBlock;

class TextareaColumn extends AbstractBlock
{
    protected function _toHtml(): string
    {
        $column = (array)$this->getData('column');
        $style = (string)($column['style'] ?? '');
        return '<textarea id="' . $this->getData('input_id') . '" name="' . $this->getData('input_name')
            . '" rows="2" class="admin__control-textarea"' . ($style !== '' ? ' style="' . $style . '"' : '')
            . '><%- ' . $this->getData('column_name') . ' %></textarea>';
    }
}
