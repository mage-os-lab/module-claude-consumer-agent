<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Surface;

use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Model\Config\Source\SurfaceMode;

final class Resolver
{
    private const PATH_CHECKOUT_SIDEBAR_DISPLAY = 'checkout/sidebar/display';
    private const CART_DRAWER_BLOCK = 'cart-drawer';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function resolve(int $storeId, ?LayoutInterface $layout): string
    {
        $mode = $this->storeConfig->agent($storeId)->surfaceMode;
        if ($mode !== SurfaceMode::AUTO) {
            return $mode;
        }
        $sidebarEnabled = $this->scopeConfig->isSetFlag(
            self::PATH_CHECKOUT_SIDEBAR_DISPLAY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!$sidebarEnabled) {
            return SurfaceMode::OVERLAY;
        }
        if ($layout === null) {
            return SurfaceMode::SIDE_CART;
        }
        return $layout->getBlock(self::CART_DRAWER_BLOCK) ? SurfaceMode::SIDE_CART : SurfaceMode::OVERLAY;
    }
}
