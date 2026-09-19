<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\Enrich;

use MageOS\ClaudeConsumerAgent\Model\Agent\Exception\PresentationRefused;
use MageOS\ClaudeConsumerAgent\Model\Agent\Presentation\EnrichmentContext;

final class Products
{
    private const MAX_ITEMS = 12;

    private const NOTE_TITLE_MAX_CHARS = 80;

    private const NOTE_VALUES_MAX_CHARS = 120;

    public function __construct(
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\ClaudeConsumerAgent\Model\Agent\Fencing\Sanitizer $sanitizer
    ) {
    }

    public static function matchesOptionValues(array $optionValues, array $wanted): bool
    {
        $normalized = [];
        foreach ($optionValues as $label => $value) {
            $normalized[self::normalizeOption((string)$label)] = self::normalizeOption((string)$value);
        }
        foreach ($wanted as $label => $value) {
            $expected = self::normalizeOption((string)$value);
            if (($normalized[self::normalizeOption((string)$label)] ?? null) !== $expected) {
                return false;
            }
        }
        return true;
    }

    public function __invoke(array $input, EnrichmentContext $ctx): array
    {
        $items = [];
        $dropped = [];
        $unmatched = [];
        $picks = is_array($input['picks'] ?? null) ? $input['picks'] : [];
        $isShortlist = count($picks) > 1;
        foreach ($picks as $pick) {
            $productId = (string)($pick['product_id'] ?? '');
            $product = $ctx->state->seen($productId);
            if ($product === null) {
                $dropped[] = $productId;
                continue;
            }
            $reason = $pick['reason'] ?? null;
            $wanted = is_array($pick['option_values'] ?? null) ? $pick['option_values'] : [];
            $options = is_array($product['options'] ?? null) ? $product['options'] : [];
            $variantOf = $product['variant_of'] ?? null;
            $isFamily = $options !== [] && ($variantOf === null || $variantOf === '');
            if ($isFamily && $isShortlist && $wanted === []) {
                $items[] = $this->collapsedFamilyItem($product, $reason, $options);
                continue;
            }
            if ($isFamily) {
                $cachedVariants = $this->cachedVariants($productId, $ctx);
                if ($cachedVariants !== null) {
                    $matchingVariants = $this->matchingVariants($cachedVariants, $wanted);
                    if ($matchingVariants === []) {
                        $unmatched[] = $this->noteUnmatched($productId, $product, $wanted, $ctx);
                    }
                    foreach ($this->buildVariantItems($matchingVariants, $product, $reason) as $variantItem) {
                        $items[] = $variantItem;
                    }
                    continue;
                }
                $variantItems = $this->expandFamily($productId, $product, $reason, $wanted, $ctx);
                if ($variantItems !== null) {
                    if ($variantItems === []) {
                        $unmatched[] = $this->noteUnmatched($productId, $product, $wanted, $ctx);
                    }
                    foreach ($variantItems as $variantItem) {
                        $items[] = $variantItem;
                    }
                    continue;
                }
            }
            $items[] = [
                'product' => $product,
                'reason' => $reason,
                'option_values' => is_array($product['option_values'] ?? null) ? $product['option_values'] : [],
                'variant_of' => $variantOf,
            ];
        }
        if ($items === [] && $unmatched !== []) {
            throw new PresentationRefused(
                'No variant matches the requested option_values. ' . implode(' ', $unmatched)
                . ' Present the product without option_values or pick another product.'
            );
        }
        if ($items === []) {
            throw new PresentationRefused(
                "None of those product_ids came from this session's catalog results. "
                . 'Search first and pick from the results.',
                'provenance'
            );
        }
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }
        if ($dropped !== []) {
            $ctx->notes[] = 'Skipped unknown product_ids not seen in this session: '
                . implode(', ', $dropped) . '.';
        }
        $payload = [
            'layout' => (string)($input['layout'] ?? 'carousel'),
            'items' => $items,
        ];
        if (isset($input['title'])) {
            $payload = ['title' => $input['title']] + $payload;
        }
        return $payload;
    }

    private function collapsedFamilyItem(array $product, mixed $reason, array $options): array
    {
        $summary = $this->optionsSummary($options);
        $note = $summary !== '' ? 'Available in ' . $summary . '.' : '';
        $hasReason = is_string($reason) && $reason !== '';
        $combinedReason = match (true) {
            $hasReason && $note !== '' => $reason . ' ' . $note,
            $note !== '' => $note,
            default => $reason,
        };
        return [
            'product' => $product,
            'reason' => $combinedReason,
            'option_values' => is_array($product['option_values'] ?? null) ? $product['option_values'] : [],
            'variant_of' => null,
        ];
    }

    private function optionsSummary(array $options): string
    {
        $multiLabel = count($options) > 1;
        $phrases = [];
        foreach ($options as $label => $values) {
            if (!is_array($values) || $values === []) {
                continue;
            }
            $joined = $this->sanitizer->text(
                implode(', ', array_map('strval', $values)),
                self::NOTE_VALUES_MAX_CHARS
            );
            $phrases[] = $multiLabel
                ? $this->sanitizer->text((string)$label, self::NOTE_TITLE_MAX_CHARS) . ' (' . $joined . ')'
                : $joined;
        }
        return implode(', ', $phrases);
    }

    private function expandFamily(
        string $productId,
        array $product,
        mixed $reason,
        array $wanted,
        EnrichmentContext $ctx
    ): ?array {
        try {
            $details = $ctx->backend->getProductDetails($ctx->context, $productId);
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'product details lookup failed during variant expansion',
                ['product_id' => $productId, 'exception' => $exception]
            );
            return null;
        }
        if ($details === null) {
            return null;
        }
        $variants = $details->getVariants();
        if ($variants === []) {
            return null;
        }
        $variantRecords = [];
        foreach ($variants as $variant) {
            $variantRecords[] = $variant->toArray();
        }
        $ctx->state->rememberProducts($variantRecords);
        $matchingVariants = $this->matchingVariants($variantRecords, $wanted);
        if ($matchingVariants === []) {
            return [];
        }
        $items = $this->buildVariantItems($matchingVariants, $product, $reason);
        $title = $this->sanitizer->text((string)($product['title'] ?? ''), self::NOTE_TITLE_MAX_CHARS);
        $ctx->notes[] = 'Expanded ' . $title . ' into ' . count($items) . ' variants.';
        return $items;
    }

    private function cachedVariants(string $productId, EnrichmentContext $ctx): ?array
    {
        $variants = [];
        foreach ($ctx->state->seenProducts as $record) {
            if (($record['variant_of'] ?? null) === $productId) {
                $variants[] = $record;
            }
        }
        return $variants !== [] ? $variants : null;
    }

    private function matchingVariants(array $variantRecords, array $wanted): array
    {
        return array_values(array_filter(
            $variantRecords,
            static fn (array $record): bool => self::matchesOptionValues(
                is_array($record['option_values'] ?? null) ? $record['option_values'] : [],
                $wanted
            )
        ));
    }

    private function noteUnmatched(string $productId, array $product, array $wanted, EnrichmentContext $ctx): string
    {
        $title = $this->sanitizer->text((string)($product['title'] ?? ''), self::NOTE_TITLE_MAX_CHARS);
        $labels = array_map('strval', array_keys(is_array($product['options'] ?? null) ? $product['options'] : []));
        $variantRecords = $this->cachedVariants($productId, $ctx) ?? [];
        $unknownLabels = [];
        $pairs = [];
        $available = [];
        foreach ($wanted as $label => $value) {
            $familyLabel = $this->familyLabel((string)$label, $labels);
            if ($familyLabel === null) {
                $unknownLabels[] = (string)$label;
                continue;
            }
            $pairs[] = $label . ': ' . $value;
            $values = $this->variantValues($familyLabel, $variantRecords);
            if ($values !== []) {
                $available[] = $this->sanitizer->text(
                    'its ' . $familyLabel . ' values are ' . implode(', ', $values),
                    self::NOTE_VALUES_MAX_CHARS
                );
            }
        }
        if ($unknownLabels !== []) {
            $detail = $title . ' has no option '
                . $this->sanitizer->text(implode(', ', $unknownLabels), self::NOTE_VALUES_MAX_CHARS)
                . '; its options are ' . $this->sanitizer->text(implode(', ', $labels), self::NOTE_VALUES_MAX_CHARS)
                . '.';
        } else {
            $detail = 'No variant of ' . $title . ' matches '
                . $this->sanitizer->text(implode(', ', $pairs), self::NOTE_VALUES_MAX_CHARS)
                . ($available !== [] ? '; ' . implode('; ', $available) : '') . '.';
        }
        $ctx->notes[] = $detail . ' It is not on the card.';
        return $detail;
    }

    private function familyLabel(string $label, array $labels): ?string
    {
        foreach ($labels as $candidate) {
            if (self::normalizeOption($candidate) === self::normalizeOption($label)) {
                return $candidate;
            }
        }
        return null;
    }

    private function variantValues(string $label, array $variantRecords): array
    {
        $values = [];
        foreach ($variantRecords as $record) {
            $value = $record['option_values'][$label] ?? null;
            if (is_string($value) && $value !== '' && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        return $values;
    }

    private static function normalizeOption(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    private function buildVariantItems(array $variantRecords, array $product, mixed $reason): array
    {
        $inStock = [];
        $outOfStock = [];
        foreach ($variantRecords as $record) {
            if ($record['in_stock'] ?? true) {
                $inStock[] = $record;
            } else {
                $outOfStock[] = $record;
            }
        }
        $hasReason = is_string($reason) && $reason !== '';
        $items = [];
        foreach (array_merge($inStock, $outOfStock) as $record) {
            $optionValues = is_array($record['option_values'] ?? null) ? $record['option_values'] : [];
            $record['title'] = $product['title'] ?? $record['title'];
            $items[] = [
                'product' => $record,
                'reason' => $hasReason ? $reason : null,
                'option_values' => $optionValues,
                'variant_of' => $record['variant_of'] ?? null,
            ];
        }
        return $items;
    }
}
