<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich;

use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;

final class Comparison
{
    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $entries = [];
        $dropped = [];
        foreach ((is_array($input['entries'] ?? null) ? $input['entries'] : []) as $entry) {
            $productId = (string)($entry['product_id'] ?? '');
            $product = $ctx->state->seen($productId);
            if ($product === null) {
                $dropped[] = $productId;
                continue;
            }
            $built = ['product_id' => $productId];
            $built['pros'] = is_array($entry['pros'] ?? null) ? $entry['pros'] : [];
            $built['cons'] = is_array($entry['cons'] ?? null) ? $entry['cons'] : [];
            if (isset($entry['best_for'])) {
                $built['best_for'] = $entry['best_for'];
            }
            $built['product'] = $product;
            $entries[] = $built;
        }
        if (count($entries) < 2) {
            throw new PresentationRefused(
                'A comparison needs at least 2 products whose product_ids came from this '
                . "session's catalog results.",
                'provenance'
            );
        }
        if ($dropped !== []) {
            $ctx->notes[] = 'Skipped unknown product_ids not seen in this session: '
                . implode(', ', $dropped) . '.';
        }
        $payload = [
            'entries' => $entries,
            'dimensions' => is_array($input['dimensions'] ?? null) ? $input['dimensions'] : [],
        ];
        if (isset($input['title'])) {
            $payload = ['title' => $input['title']] + $payload;
        }
        if (isset($input['recommended_product_id'])) {
            $payload['recommended_product_id'] = $input['recommended_product_id'];
        }
        $delta = $this->priceDelta($entries);
        if ($delta !== null) {
            $payload['price_delta'] = $delta;
        }
        return $payload;
    }

    private function priceDelta(array $entries): ?array
    {
        $currencies = [];
        foreach ($entries as $entry) {
            $currencies[(string)($entry['product']['currency'] ?? 'USD')] = true;
        }
        if (count($currencies) > 1) {
            return null;
        }
        $priced = [];
        foreach ($entries as $entry) {
            if (isset($entry['product']['price'])) {
                $priced[] = [(float)$entry['product']['price'], (string)$entry['product_id']];
            }
        }
        if (count($priced) < 2) {
            return null;
        }
        usort($priced, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        [$lowPrice, $lowId] = $priced[0];
        [$highPrice, $highId] = $priced[count($priced) - 1];
        $amount = round($highPrice - $lowPrice, 2);
        if ($amount <= 0) {
            return null;
        }
        return [
            'amount' => $amount,
            'low_product_id' => $lowId,
            'low_price' => $lowPrice,
            'high_product_id' => $highId,
            'high_price' => $highPrice,
        ];
    }
}
