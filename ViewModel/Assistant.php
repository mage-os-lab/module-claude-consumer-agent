<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;

final class Assistant implements ArgumentInterface
{
    public function __construct(
        private readonly \MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Surface\Resolver $surfaceResolver,
        private readonly \Magento\Framework\UrlInterface $url,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Surface\PageDetector $pageDetector
    ) {
    }

    public function snapshot(): array
    {
        $config = $this->storeConfig->agent($this->storeId());
        return [
            'urls' => [
                'start' => $this->url->getUrl('aiagent/session/start'),
                'turn' => $this->url->getUrl('aiagent/turn/index'),
                'transcript' => $this->url->getUrl('aiagent/session/transcript'),
                'reset' => $this->url->getUrl('aiagent/session/reset'),
            ],
            'surface' => $this->surfaceResolver->resolve($this->storeId(), null),
            'page' => $this->pageDetector->detect(),
            'headerIconView' => $config->headerIconView,
            'keepOpen' => $config->keepOpen,
            'streaming' => $config->streaming,
            'firstByteThreshold' => $config->firstByteThreshold,
            'assistantName' => $config->assistantName,
            'greeting' => $config->greeting,
            'starters' => $config->starters,
            'showAiLabel' => $config->showAiLabel,
            'contact' => [
                'url' => $config->contactUrl,
                'label' => $config->contactLabel,
            ],
            'maxMessageLength' => $config->maxMessageLength,
            'cards' => [
                'products' => [
                    'image' => $config->productCard['image'],
                    'price' => $config->productCard['price'],
                    'description' => $config->productCard['description'],
                    'stock' => $config->productCard['stock'],
                    'addToCart' => $config->productCard['addToCart'],
                    'reason' => $config->productCard['reason'],
                ],
            ],
            'i18n' => [
                'stillWorking' => (string)__('Still working...'),
                'reloadPage' => (string)__('Please reload the page and try again.'),
                'interrupted' => (string)__('The connection was interrupted. Please try again.'),
                'timedOut' => (string)__('This is taking longer than expected. Please try again.'),
                'cartItems' => (string)__('%1 item(s), %2'),
                'cartCount' => (string)__('%1 item(s)'),
                'cartEmpty' => (string)__('Your cart is empty'),
                'placeholder' => (string)__('Ask about products, orders or policies'),
                'placeholderReply' => (string)__('Type a reply'),
                'aiAssistant' => (string)__('AI assistant. '),
                'needPerson' => (string)__('Need a person? '),
                'productChips' => [
                    (string)__('Tell me about this product'),
                    (string)__('Find similar'),
                    (string)__('What fits with it'),
                ],
                'products' => (string)__('Products'),
                'compare' => (string)__('Compare'),
                'priceDifference' => (string)__('Price difference: %1'),
                'fulfillment' => (string)__('Fulfillment: %1'),
                'orderStatus' => [
                    'processing' => (string)__('Processing'),
                    'shipped' => (string)__('Shipped'),
                    'delivered' => (string)__('Delivered'),
                    'delayed' => (string)__('Delayed'),
                    'return_initiated' => (string)__('Return initiated'),
                    'cancelled' => (string)__('Cancelled'),
                    'refunded' => (string)__('Refunded'),
                    'unknown' => (string)__('Unknown'),
                ],
            ],
        ];
    }

    public function name(): string
    {
        return $this->storeConfig->agent($this->storeId())->assistantName;
    }

    public function greeting(): string
    {
        return $this->storeConfig->agent($this->storeId())->greeting;
    }

    public function starters(): array
    {
        return $this->storeConfig->agent($this->storeId())->starters;
    }

    public function aiLabelText(): string
    {
        if (!$this->storeConfig->agent($this->storeId())->showAiLabel) {
            return '';
        }
        return (string)__('AI');
    }

    public function contactUrl(): string
    {
        return $this->storeConfig->agent($this->storeId())->contactUrl;
    }

    public function contactLabel(): string
    {
        return $this->storeConfig->agent($this->storeId())->contactLabel;
    }

    private function storeId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }
}
