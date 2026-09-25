<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;

final class OrderStatus
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer
    ) {
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $orderId = (string)($input['order_id'] ?? '');
        $order = $ctx->backend->getOrder($ctx->context, $orderId);
        if ($order === null) {
            throw new PresentationRefused("No order with id {$orderId}. Look it up first.");
        }
        $payload = [
            'order_id' => $orderId,
            'summary' => (string)($input['summary'] ?? ''),
        ];
        if (isset($input['next_step'])) {
            $payload['next_step'] = $input['next_step'];
        }
        $payload['order'] = $this->serializer->order($order);
        return $payload;
    }
}
