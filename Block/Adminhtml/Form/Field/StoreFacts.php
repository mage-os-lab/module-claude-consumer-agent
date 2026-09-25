<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\DataObject;

class StoreFacts extends AbstractFieldArray
{
    private ?SourceColumn $sourceRenderer = null;

    private ?TextareaColumn $textareaRenderer = null;

    protected function _prepareToRender(): void
    {
        $this->addColumn('topic', ['label' => __('Topic'), 'style' => 'width:170px']);
        $this->addColumn('keywords', ['label' => __('Keywords (optional)'), 'style' => 'width:260px']);
        $this->addColumn('source', ['label' => __('Source'), 'renderer' => $this->getSourceRenderer()]);
        $this->addColumn('value', [
            'label' => __('Answer or identifier'),
            'renderer' => $this->getTextareaRenderer(),
            'style' => 'width:100%',
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Fact');
    }

    public function render(AbstractElement $element): string
    {
        $isCheckboxRequired = $this->_isInheritCheckboxRequired($element);
        if ($element->getInherit() == 1 && $isCheckboxRequired) {
            $element->setDisabled(true);
        }
        $colspan = $isCheckboxRequired ? 2 : 3;
        $html = '<td class="value" colspan="' . $colspan . '"><label for="' . $element->getHtmlId()
            . '" class="label"><span>' . $element->getLabel() . '</span></label>';
        if ($element->getScope() && !$this->_storeManager->isSingleStoreMode()) {
            $html .= '<p class="note"><span>' . $element->getScopeLabel() . '</span></p>';
        }
        $html .= $this->_getElementHtml($element);
        if ($element->getComment()) {
            $html .= '<p class="note"><span>' . $element->getComment() . '</span></p>';
        }
        $html .= '</td>';
        if ($isCheckboxRequired) {
            $html .= $this->_renderInheritCheckbox($element);
            $html .= $this->_renderHint($element);
        }
        return $this->_decorateRowHtml($element, $html);
    }

    protected function _prepareArrayRow(DataObject $row): void
    {
        $optionExtraAttr = [];
        $hash = $this->getSourceRenderer()->calcOptionHash((string)$row->getData('source'));
        $optionExtraAttr['option_' . $hash] = 'selected="selected"';
        $row->setData('option_extra_attrs', $optionExtraAttr);
    }

    private function getSourceRenderer(): SourceColumn
    {
        if ($this->sourceRenderer === null) {
            $this->sourceRenderer = $this->getLayout()->createBlock(
                SourceColumn::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }
        return $this->sourceRenderer;
    }

    private function getTextareaRenderer(): TextareaColumn
    {
        if ($this->textareaRenderer === null) {
            $this->textareaRenderer = $this->getLayout()->createBlock(TextareaColumn::class);
        }
        return $this->textareaRenderer;
    }
}
