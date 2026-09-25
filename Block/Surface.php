<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block;

use Magento\Framework\View\Element\Template;
use MageOS\AiShoppingAssistant\Model\Config\Source\SurfaceMode;
use MageOS\AiShoppingAssistant\Model\Surface\Resolver;
use MageOS\AiShoppingAssistant\ViewModel\Assistant;

class Surface extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\AiShoppingAssistant\Model\Surface\Resolver $surfaceResolver,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\AiShoppingAssistant\ViewModel\Assistant $assistant,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId)) {
            return '';
        }
        $surface = (string)$this->getData('surface');
        $resolved = $this->surfaceResolver->resolve($storeId, $this->getLayout());
        if ($surface === SurfaceMode::OVERLAY) {
            if ($resolved === SurfaceMode::SIDE_CART) {
                return '';
            }
            return parent::_toHtml();
        }
        if ($resolved !== $surface) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getAssistant(): Assistant
    {
        return $this->assistant;
    }
}
