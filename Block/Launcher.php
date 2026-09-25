<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Block;

use Magento\Framework\View\Element\Template;
use MageOS\AiShoppingAssistant\ViewModel\Assistant;

class Launcher extends Template
{
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\AiShoppingAssistant\ViewModel\Assistant $assistant,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _toHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();
        $config = $this->storeConfig->agent($storeId);
        if (!$config->enabled || !$config->launcherEnabled) {
            return '';
        }
        return parent::_toHtml();
    }

    public function getAssistant(): Assistant
    {
        return $this->assistant;
    }
}
