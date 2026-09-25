<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\AiShoppingAssistant\Api\Backend\FulfillmentProviderInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\FulfillmentOption;

final class AddressEstimate implements FulfillmentProviderInterface
{
    private const MAX_OPTIONS = 5;

    public function __construct(
        private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        private readonly \Magento\Quote\Api\ShippingMethodManagementInterface $shippingMethodManagement,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function options(SessionContext $ctx, array $productIds): array
    {
        if ($ctx->customerId === null) {
            return [];
        }

        try {
            $customer = $this->customerRepository->getById($ctx->customerId);
        } catch (NoSuchEntityException $exception) {
            return [];
        }

        $addressId = $customer->getDefaultShipping();
        if ($addressId === null) {
            return [];
        }

        try {
            $methods = $this->shippingMethodManagement->estimateByAddressId($ctx->quoteId, (int)$addressId);
        } catch (\Throwable $exception) {
            $this->logger->warning($exception->getMessage());
            return [];
        }

        $options = [];
        foreach ($methods as $method) {
            if (!$method->getAvailable()) {
                continue;
            }
            $options[] = new FulfillmentOption(
                FulfillmentOptionInterface::METHOD_SHIPPING,
                trim($method->getCarrierTitle() . ' ' . $method->getMethodTitle()),
                (float)$method->getAmount()
            );
            if (count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $options;
    }
}
