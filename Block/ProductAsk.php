<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block;

use Magento\Framework\View\Element\Template;

class ProductAsk extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Catalog\Helper\Data $catalogHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId) || $this->catalogHelper->getProduct() === null) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getProductId(): string
    {
        $product = $this->catalogHelper->getProduct();
        return $product === null ? '' : (string)$product->getId();
    }
}
