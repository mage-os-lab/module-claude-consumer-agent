<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Gate;

use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;

final class Options
{
    public const NAME = 'options';

    private const UNSETTABLE_CUSTOM_OPTION_TYPES = ['file', 'date', 'date_time', 'time'];

    private const STANDALONE_VALUE_MIN_LENGTH = 2;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer $sanitizer
    ) {
    }

    public function check(SessionState $state, string $productId, array $options = []): ?ToolOutcome
    {
        $product = $state->seen($productId);
        if ($product === null) {
            return null;
        }
        $productOptions = is_array($product['options'] ?? null) ? $product['options'] : [];
        if ($productOptions !== []) {
            return ToolOutcome::held(self::NAME, $this->optionsMessage($productId, $productOptions));
        }
        if ((bool)($product['has_required_custom_options'] ?? false)) {
            $customOptions = is_array($product['custom_options'] ?? null) ? $product['custom_options'] : [];
            if ($this->hasUnsettableRequiredOption($customOptions)) {
                return ToolOutcome::held(self::NAME, $this->customOptionsMessage($productId, $product));
            }
            $missing = $this->missingRequiredOptionTitles($customOptions, $options);
            if ($missing !== []) {
                return ToolOutcome::held(self::NAME, $this->missingCustomOptionsMessage($productId, $missing));
            }
        }
        $optionValues = is_array($product['option_values'] ?? null) ? $product['option_values'] : [];
        if ($optionValues !== [] && !$this->valuesConfirmed($optionValues, $state->recentCustomerText)) {
            return ToolOutcome::held(self::NAME, $this->variantMessage($productId, $product, $optionValues));
        }
        return null;
    }

    private function hasUnsettableRequiredOption(array $customOptions): bool
    {
        if ($customOptions === []) {
            return true;
        }
        foreach ($customOptions as $option) {
            if (!(bool)($option['required'] ?? false)) {
                continue;
            }
            if (in_array((string)($option['type'] ?? ''), self::UNSETTABLE_CUSTOM_OPTION_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    private function missingRequiredOptionTitles(array $customOptions, array $options): array
    {
        $missing = [];
        foreach ($customOptions as $option) {
            if (!(bool)($option['required'] ?? false)) {
                continue;
            }
            $title = (string)($option['title'] ?? '');
            if ($title === '' || $this->hasOptionTitle($options, $title)) {
                continue;
            }
            $missing[] = $title;
        }
        return $missing;
    }

    private function hasOptionTitle(array $options, string $title): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_string($key) && strcasecmp($key, $title) === 0) {
                return true;
            }
        }
        return false;
    }

    private function optionsMessage(string $productId, array $options): string
    {
        $names = $this->sanitizer->text(implode(', ', array_keys($options)), 60);
        return 'product_id ' . $productId . ' has options still to choose (' . $names . '), so the '
            . 'cart takes one of its variants. Settle each option from what the customer said, '
            . 'ask once with the values as chips when one is still open, then add the matching '
            . "variant's product_id from the variants get_product_details returns for this id.";
    }

    private function customOptionsMessage(string $productId, array $product): string
    {
        $attributes = is_array($product['attributes'] ?? null) ? $product['attributes'] : [];
        $titles = $this->sanitizer->text(implode(', ', array_keys($attributes)), 80);
        $url = (string)($product['url'] ?? '');
        return 'product_id ' . $productId . ' needs choices that are made on its product page ('
            . $titles . '). Give the customer the link ' . $url
            . ' and say which choices the page asks for; do not add it here.';
    }

    private function missingCustomOptionsMessage(string $productId, array $missingTitles): string
    {
        $titles = $this->sanitizer->text(implode(', ', $missingTitles), 80);
        return 'product_id ' . $productId . ' needs choices for ' . $titles . '. Ask the customer '
            . 'to choose from the values in the record, offering them as chips, then call '
            . 'add_to_cart with options {"<option title>": "<value title>"}.';
    }

    private function variantMessage(string $productId, array $product, array $optionValues): string
    {
        $values = $this->sanitizer->text(implode(' / ', array_values($optionValues)), 120);
        $names = $this->sanitizer->text(implode(' and ', array_keys($optionValues)), 60);
        $familyRef = (string)($product['variant_of'] ?? '');
        if ($familyRef === '') {
            $familyRef = (string)($product['title'] ?? '');
        }
        $familyRef = $this->sanitizer->text($familyRef, 80);
        return 'product_id ' . $productId . ' is the ' . $values . ' variant of ' . $familyRef
            . ', but the customer has not chosen these values in this conversation. Show the '
            . "variants with present_products (put the option values in each pick's reason), ask "
            . 'which ' . $names . ' they want, and add the matching variant only after they choose.';
    }

    private function valuesConfirmed(array $optionValues, array $recentCustomerText): bool
    {
        $haystack = ' ' . $this->normalize(implode(' ', $recentCustomerText)) . ' ';
        foreach ($optionValues as $name => $label) {
            $needle = $this->normalize((string)$label);
            if ($needle === '') {
                return false;
            }
            if (!$this->valuePresent($haystack, $needle, $this->normalize((string)$name))) {
                return false;
            }
        }
        return true;
    }

    private function valuePresent(string $haystack, string $needle, string $name): bool
    {
        if (mb_strlen($needle) >= self::STANDALONE_VALUE_MIN_LENGTH) {
            return str_contains($haystack, ' ' . $needle . ' ');
        }
        if ($name === '') {
            return false;
        }
        return str_contains($haystack, ' ' . $name . ' ' . $needle . ' ')
            || str_contains($haystack, ' ' . $needle . ' ' . $name . ' ');
    }

    private function normalize(string $text): string
    {
        $lower = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $lower);
        return trim((string)$normalized);
    }
}
