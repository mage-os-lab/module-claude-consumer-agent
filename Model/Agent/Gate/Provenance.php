<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Gate;

use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class Provenance
{
    public const NAME = 'provenance';

    public function check(SessionState $state, string $productId): ?ToolOutcome
    {
        if ($state->hasSeen($productId)) {
            return null;
        }
        return ToolOutcome::held(self::NAME, $this->message($productId));
    }

    private function message(string $productId): string
    {
        return 'product_id ' . $productId . ' was not returned by catalog or order tools in this '
            . 'session. Resolve it first: call get_product_details with this exact id (text '
            . 'search does not match product ids), or find it via search or order history, '
            . 'then add it using a product_id from those results.';
    }
}
