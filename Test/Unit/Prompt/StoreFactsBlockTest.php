<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StoreFactsBlock;
use PHPUnit\Framework\TestCase;

final class StoreFactsBlockTest extends TestCase
{
    private function block(): StoreFactsBlock
    {
        return new StoreFactsBlock();
    }

    public function testTextFactPrintsTheAnswerVerbatim(): void
    {
        $text = $this->block()->text(
            [
                [
                    'topic' => 'Price match',
                    'keywords' => ['price match', 'price guarantee'],
                    'source' => 'text',
                    'value' => 'We match any advertised Canadian price within 14 days.',
                ],
            ],
            [],
            6000
        );

        $this->assertStringContainsString('# Store facts', $text);
        $this->assertStringContainsString(
            '- Price match (price match, price guarantee): We match any advertised Canadian '
                . 'price within 14 days.',
            $text
        );
    }

    public function testHeaderTellsCompleteRowsFromPagePointersAndOrderLookups(): void
    {
        $text = $this->block()->text(
            [
                ['topic' => 'Price match', 'keywords' => [], 'source' => 'text', 'value' => 'We match prices.'],
            ],
            [],
            6000
        );

        $this->assertStringContainsString(
            'A row that carries its answer here is complete: answer from it without a tool call. '
                . 'A row that points at a page or block needs search_policies. A question about a '
                . 'service is not an order lookup.',
            $text
        );
    }

    public function testCmsPageFactPrintsTheResolvedTitle(): void
    {
        $text = $this->block()->text(
            [
                [
                    'topic' => 'Returns',
                    'keywords' => ['return', 'refund', 'exchange'],
                    'source' => 'cms_page',
                    'value' => 'returns',
                    'title' => 'Returns and Refund Policy',
                ],
            ],
            [],
            6000
        );

        $this->assertStringContainsString(
            '- Returns (return, refund, exchange): covered by the page "Returns and Refund '
                . 'Policy"; call search_policies with these words for the terms.',
            $text
        );
    }

    public function testCmsPageFactWithoutAResolvedTitleFallsBackToTheIdentifier(): void
    {
        $text = $this->block()->text(
            [
                ['topic' => 'Returns', 'keywords' => [], 'source' => 'cms_page', 'value' => 'returns'],
            ],
            [],
            6000
        );

        $this->assertStringContainsString('covered by the page "returns";', $text);
    }

    public function testCmsBlockFactPrintsTheResolvedTitle(): void
    {
        $text = $this->block()->text(
            [
                [
                    'topic' => 'Care instructions',
                    'keywords' => ['care', 'cleaning'],
                    'source' => 'cms_block',
                    'value' => 'care-guide',
                    'title' => 'Care guide',
                ],
            ],
            [],
            6000
        );

        $this->assertStringContainsString(
            '- Care instructions (care, cleaning): covered by the block "Care guide"; call '
                . 'search_policies with these words for the terms.',
            $text
        );
    }

    public function testNotOfferedFactsCollectIntoOneTrailingLowercasedLine(): void
    {
        $text = $this->block()->text(
            [
                ['topic' => 'Gift wrapping', 'keywords' => [], 'source' => 'not_offered', 'value' => ''],
                ['topic' => 'Layaway', 'keywords' => [], 'source' => 'not_offered', 'value' => ''],
                ['topic' => 'Price match', 'keywords' => [], 'source' => 'text', 'value' => 'We match prices.'],
            ],
            [],
            6000
        );

        $lines = explode("\n", trim($text));
        $this->assertSame('- Not offered here: gift wrapping, layaway.', end($lines));
    }

    public function testCoreLinesFollowConfiguredRows(): void
    {
        $text = $this->block()->text(
            [
                ['topic' => 'Price match', 'keywords' => [], 'source' => 'text', 'value' => 'We match prices.'],
            ],
            ['Payment methods: Credit card.', 'Guest checkout: allowed.'],
            6000
        );

        $priceMatchPosition = strpos($text, 'Price match');
        $paymentPosition = strpos($text, 'Payment methods');
        $guestCheckoutPosition = strpos($text, 'Guest checkout');

        $this->assertNotFalse($priceMatchPosition);
        $this->assertNotFalse($paymentPosition);
        $this->assertNotFalse($guestCheckoutPosition);
        $this->assertLessThan($paymentPosition, $priceMatchPosition);
        $this->assertLessThan($guestCheckoutPosition, $paymentPosition);
        $this->assertStringContainsString('- Payment methods: Credit card.', $text);
        $this->assertStringContainsString('- Guest checkout: allowed.', $text);
    }

    public function testEmptyFactsAndCoreLinesReturnEmptyString(): void
    {
        $this->assertSame('', $this->block()->text([], [], 6000));
    }

    public function testTruncatesByDroppingWholeLinesFromTheEnd(): void
    {
        $facts = [];
        for ($i = 1; $i <= 50; $i++) {
            $facts[] = [
                'topic' => 'Topic ' . $i,
                'keywords' => [],
                'source' => 'text',
                'value' => str_repeat('x', 40),
            ];
        }

        $text = $this->block()->text($facts, [], 700);

        $this->assertLessThanOrEqual(700, mb_strlen($text));
        $this->assertStringContainsString('- ... (list truncated)', $text);
        $this->assertStringContainsString('# Store facts', $text);
    }

    public function testWithinBudgetTextIsNotTruncated(): void
    {
        $text = $this->block()->text(
            [
                ['topic' => 'Price match', 'keywords' => [], 'source' => 'text', 'value' => 'We match prices.'],
            ],
            [],
            6000
        );

        $this->assertStringNotContainsString('list truncated', $text);
    }
}
