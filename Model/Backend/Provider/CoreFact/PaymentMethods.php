<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class PaymentMethods implements CoreFactProviderInterface
{
    public function __construct(
        private readonly \Magento\Payment\Api\PaymentMethodListInterface $paymentMethodList,
        private readonly array $excludedCodes = []
    ) {
    }

    public function line(int $storeId): ?string
    {
        $titles = [];
        $seenTitles = [];
        foreach ($this->paymentMethodList->getActiveList($storeId) as $method) {
            if (in_array((string)$method->getCode(), $this->excludedCodes, true)) {
                continue;
            }
            $title = trim((string)$method->getTitle());
            if ($title === '') {
                continue;
            }
            $key = mb_strtolower($title);
            if (isset($seenTitles[$key])) {
                continue;
            }
            $seenTitles[$key] = true;
            $titles[] = $title;
        }
        if ($titles === []) {
            return null;
        }
        return 'Payment methods: ' . implode(', ', $titles) . '.';
    }
}
