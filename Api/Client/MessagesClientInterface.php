<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Client;

use Generator;

/**
 * stream() yields one MageOS\AiShoppingAssistant\Model\Client\RawEvent per SSE event of the response, in order.
 */
interface MessagesClientInterface
{
    public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): Generator;
}
