<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits;

final class SlotHandle
{
    private bool $released = false;

    public function __construct(
        private readonly \Magento\Framework\Lock\LockManagerInterface $lockManager,
        private readonly string $name
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->lockManager->unlock($this->name);
    }
}
