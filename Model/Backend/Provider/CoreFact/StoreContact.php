<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreFact;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Api\Prompt\CoreFactProviderInterface;

final class StoreContact implements CoreFactProviderInterface
{
    private const PATH_PHONE = 'general/store_information/phone';
    private const PATH_EMAIL = 'trans_email/ident_general/email';

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function line(int $storeId): ?string
    {
        $phone = trim((string)$this->scopeConfig->getValue(self::PATH_PHONE, ScopeInterface::SCOPE_STORE, $storeId));
        $email = trim((string)$this->scopeConfig->getValue(self::PATH_EMAIL, ScopeInterface::SCOPE_STORE, $storeId));
        if ($phone === '' && $email === '') {
            return null;
        }

        $parts = [];
        if ($phone !== '') {
            $parts[] = 'phone ' . $phone;
        }
        if ($email !== '') {
            $parts[] = 'email ' . $email;
        }
        return 'Store contact: ' . implode(', ', $parts) . '.';
    }
}
