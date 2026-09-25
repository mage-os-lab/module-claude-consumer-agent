<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

final class CartSection implements ArgumentInterface
{
    public function __construct(
        private readonly \Magento\Framework\UrlInterface $url
    ) {
    }

    public function keys(): array
    {
        return [
            'count' => 'summary_count',
            'subtotal' => 'subtotal',
            'subtotalAmount' => 'subtotalAmount',
            'items' => 'items',
        ];
    }

    public function cartUrl(): string
    {
        return $this->url->getUrl('checkout/cart');
    }

    public function checkoutUrl(): string
    {
        return $this->url->getUrl('checkout');
    }
}
