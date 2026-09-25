<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Data\UserPreferences;
use PHPUnit\Framework\TestCase;

final class DynamicContextTest extends TestCase
{
    private function buildContext(): DynamicContext
    {
        return new DynamicContext(new Fence(new Sanitizer()));
    }

    public function testBlockStartsWithHeaderAndIsFenced(): void
    {
        $context = $this->buildContext();
        $text = $context->build(null, null, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringStartsWith("# Session context\n\n<storefront_data>", $text);
        $this->assertStringContainsString('</storefront_data>', $text);
    }

    public function testCustomerBlockUsesPreferenceFields(): void
    {
        $context = $this->buildContext();
        $prefs = new UserPreferences('u-1', 'Priya', 'gold', 'Lisbon', ['budget' => 'low']);
        $text = $context->build($prefs, null, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringContainsString('Priya', $text);
        $this->assertStringContainsString('gold', $text);
        $this->assertStringContainsString('Lisbon', $text);
        $this->assertStringContainsString('"saved_memory":"none"', str_replace(' ', '', $text));
    }

    public function testCartItemsCarryProductIdTitleQuantityAndOptionalOptionValues(): void
    {
        $context = $this->buildContext();
        $withOptions = new CartItem('p-100', 'Tent', 149.0, 1, null, ['color' => 'green']);
        $withoutOptions = new CartItem('p-200', 'Poles', 20.0, 2);
        $cart = new Cart([$withOptions, $withoutOptions]);
        $text = $context->build(null, $cart, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringContainsString('p-100', $text);
        $this->assertStringContainsString('option_values', $text);
        $this->assertStringContainsString('p-200', $text);
    }

    public function testCurrentPageAlwaysCarriesPageType(): void
    {
        $context = $this->buildContext();
        $page = new PageContext(PageContext::PAGE_TYPE_PRODUCT, 'p-100');
        $text = $context->build(null, null, $page, '2026-05-30T10:00+01:00');
        $this->assertStringContainsString('page_type', $text);
        $this->assertStringContainsString('product', $text);
    }

    public function testLocalTimeIsCarriedVerbatim(): void
    {
        $context = $this->buildContext();
        $text = $context->build(null, null, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringContainsString('2026-05-30T10:00+01:00', $text);
    }

    public function testNoCartMeansNoCartKey(): void
    {
        $context = $this->buildContext();
        $text = $context->build(null, null, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringNotContainsString('"cart"', $text);
    }

    public function testFencedAtSixThousandChars(): void
    {
        $context = $this->buildContext();
        $items = [];
        for ($i = 0; $i < 400; $i++) {
            $items[] = new CartItem('p-' . $i, str_repeat('x', 40), 10.0, 1);
        }
        $cart = new Cart($items);
        $text = $context->build(null, $cart, new PageContext(), '2026-05-30T10:00+01:00');
        $this->assertStringContainsString('...[truncated]', $text);
    }
}
