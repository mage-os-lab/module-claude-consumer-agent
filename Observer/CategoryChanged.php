<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Not final: Magento generates an interceptor for observers.
 */
class CategoryChanged implements ObserverInterface
{
    private const CACHE_TAG = 'AIAGENT';

    public function __construct(
        private readonly \Magento\Framework\App\CacheInterface $cache
    ) {
    }

    public function execute(Observer $observer): void
    {
        $this->cache->clean([self::CACHE_TAG]);
    }
}
