<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Not final: Magento generates an interceptor for observers.
 */
class HyvaConfigGenerateBefore implements ObserverInterface
{
    private const MODULE_NAME = 'MageOS_AiShoppingAssistant';

    public function __construct(
        private readonly \Magento\Framework\Component\ComponentRegistrarInterface $registrar
    ) {
    }

    public function execute(Observer $observer): void
    {
        $config = $observer->getEvent()->getData('config');
        $path = $this->registrar->getPath(ComponentRegistrar::MODULE, self::MODULE_NAME);
        if ($path === null || strpos($path, BP) !== 0) {
            return;
        }
        $relativePath = substr($path, strlen(BP) + 1);
        $extensions = $config->getData('extensions') ?: [];
        $extensions[] = ['src' => $relativePath];
        $config->setData('extensions', $extensions);
    }
}
