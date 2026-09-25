<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Request;

final class FormKeyGuard
{
    private const HEADER = 'X-Form-Key';

    public function __construct(
        private readonly \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator,
        private readonly \Magento\Framework\Data\Form\FormKey $formKey
    ) {
    }

    public function isValid(\Magento\Framework\App\RequestInterface $request): bool
    {
        if ($this->formKeyValidator->validate($request)) {
            return true;
        }
        $header = $request->getHeader(self::HEADER);
        if ($header === false) {
            return false;
        }
        return hash_equals($this->formKey->getFormKey(), (string)$header);
    }
}
