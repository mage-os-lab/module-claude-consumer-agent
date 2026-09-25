<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class GetOrderStatus implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $orderId = (string)($input['order_id'] ?? '');
        $order = $this->backend->getOrder($context, $orderId);
        if ($order === null) {
            return ToolOutcome::error("No order with id {$orderId} for this customer.");
        }
        $payload = $this->serializer->order($order);
        $text = "Order status:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $this->orderItemsToProducts($order));
    }

    private function orderItemsToProducts(OrderInterface $order): array
    {
        $products = [];
        foreach ($order->getItems() as $item) {
            $products[] = [
                'product_id' => $item->getProductId(),
                'title' => $item->getTitle(),
                'price' => $item->getPrice(),
                'currency' => $order->getCurrency(),
                'option_values' => $item->getOptionValues(),
                'variant_of' => $item->getVariantOf(),
                'in_stock' => true,
            ];
        }
        return $products;
    }
}
