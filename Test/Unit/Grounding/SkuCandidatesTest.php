<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Grounding;

use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use PHPUnit\Framework\TestCase;

final class SkuCandidatesTest extends TestCase
{
    private SkuCandidates $candidates;

    protected function setUp(): void
    {
        $this->candidates = new SkuCandidates();
    }

    public function testATrailingPeriodIsNotPartOfTheCandidate(): void
    {
        $this->assertSame(['24-MB01'], $this->candidates->fromText('Tell me about 24-MB01.'));
        $this->assertSame(['ABC.123'], $this->candidates->fromText('Is ABC.123 in stock?'));
    }

    public function testKeepsTokensThatContainADigit(): void
    {
        $this->assertSame(['24-MB01'], $this->candidates->fromText('do you have 24-MB01 in stock'));
        $this->assertSame(['MH01-XS-Black', 'WS12'], $this->candidates->fromText('compare MH01-XS-Black with WS12'));
    }

    public function testTrimsPunctuationAroundATokenAndKeepsInnerCharacters(): void
    {
        $this->assertSame(['24-MB01'], $this->candidates->fromText('do you have "24-MB01"?'));
        $this->assertSame(['24-MB01'], $this->candidates->fromText('(24-MB01),'));
        $this->assertSame(['MH01_v2.1/A'], $this->candidates->fromText('*MH01_v2.1/A*'));
    }

    public function testDropsPureWordsAndTokensWithoutADigit(): void
    {
        $this->assertSame([], $this->candidates->fromText('I have kids at home'));
        $this->assertSame([], $this->candidates->fromText('Add the AR-lantern to my cart.'));
        $this->assertSame([], $this->candidates->fromText(''));
    }

    public function testKeepsOnlyTokensBetweenThreeAndSixtyFourCharacters(): void
    {
        $sixtyFour = str_repeat('A', 63) . '1';
        $sixtyFive = str_repeat('A', 64) . '1';
        $this->assertSame([], $this->candidates->fromText('I have 2 kids and a1 dog'));
        $this->assertSame(['a12', '123'], $this->candidates->fromText('a12 123'));
        $this->assertSame([$sixtyFour], $this->candidates->fromText($sixtyFour . ' ' . $sixtyFive));
    }

    public function testDeduplicatesCaseInsensitivelyKeepingTheFirstSpelling(): void
    {
        $this->assertSame(['24-MB01'], $this->candidates->fromText('24-MB01 or 24-mb01 or 24-MB01'));
    }

    public function testReturnsAtMostEightCandidatesInOrderOfAppearance(): void
    {
        $tokens = [];
        for ($i = 1; $i <= 10; $i++) {
            $tokens[] = 'SKU-' . $i;
        }
        $this->assertSame(array_slice($tokens, 0, 8), $this->candidates->fromText(implode(' ', $tokens)));
    }
}
