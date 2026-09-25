<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block\Adminhtml\Form\Field;

class SourceColumn extends \Magento\Framework\View\Element\Html\Select
{
    private const OPTIONS = [
        'text' => 'Text answer',
        'cms_page' => 'CMS page',
        'cms_block' => 'CMS block',
        'not_offered' => 'Not offered',
    ];

    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }

    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            foreach (self::OPTIONS as $value => $label) {
                $this->addOption($value, __($label));
            }
        }
        return parent::_toHtml();
    }
}
