<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CatalogMap;
use PHPUnit\Framework\TestCase;

final class CatalogMapTest extends TestCase
{
    public function testEmptyNodesProduceEmptyText(): void
    {
        $map = new CatalogMap();
        $this->assertSame('', $map->text([], 6000));
    }

    public function testDepthOnePrintsRootsOnly(): void
    {
        $nodes = [
            ['id' => 175, 'name' => 'Shop by Type', 'children' => []],
            ['id' => 176, 'name' => 'Shop by Space', 'children' => []],
        ];
        $map = new CatalogMap();
        $this->assertSame(
            "- Shop by Type [175]\n- Shop by Space [176]",
            $map->text($nodes, 6000)
        );
    }

    public function testDepthTwoPrintsRootAndChildren(): void
    {
        $nodes = [
            [
                'id' => 175,
                'name' => 'Shop by Type',
                'children' => [
                    ['id' => 5, 'name' => 'Seating', 'children' => []],
                    ['id' => 6, 'name' => 'Tables', 'children' => []],
                ],
            ],
        ];
        $map = new CatalogMap();
        $this->assertSame(
            '- Shop by Type [175]: Seating [5], Tables [6]',
            $map->text($nodes, 6000)
        );
    }

    public function testDepthThreePrintsOneLinePerChildInsteadOfNestedParens(): void
    {
        $nodes = [
            [
                'id' => 175,
                'name' => 'Shop by Type',
                'children' => [
                    [
                        'id' => 5,
                        'name' => 'Seating',
                        'children' => [
                            ['id' => 1234, 'name' => 'Sofas', 'children' => []],
                            ['id' => 1235, 'name' => 'Lounge Chairs', 'children' => []],
                        ],
                    ],
                    ['id' => 6, 'name' => 'Tables', 'children' => []],
                ],
            ],
        ];
        $map = new CatalogMap();
        $text = $map->text($nodes, 6000);

        $this->assertSame(
            "- Shop by Type [175]: Seating [5], Tables [6]\n"
                . '  - Seating [5]: Sofas [1234], Lounge Chairs [1235]',
            $text
        );
        $this->assertStringNotContainsString('Sofas [1234], Lounge Chairs [1235] (', $text);
    }

    public function testTextUnderTheLimitIsNotTruncated(): void
    {
        $nodes = [['id' => 1, 'name' => 'Root', 'children' => []]];
        $map = new CatalogMap();
        $this->assertSame('- Root [1]', $map->text($nodes, 6000));
    }

    public function testTextOverTheLimitCutsWholeLinesAndAppendsTheTruncationLine(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 10; $i++) {
            $nodes[] = ['id' => $i, 'name' => sprintf('Node%02d', $i), 'children' => []];
        }
        $map = new CatalogMap();
        $text = $map->text($nodes, 100);

        $lines = explode("\n", $text);
        $lastLine = array_pop($lines);

        $this->assertSame('- ... (map truncated; use search_categories for the rest)', $lastLine);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^- Node\d\d \[\d+\]$/', $line);
        }
        $this->assertStringNotContainsString('Node10', $text);
        $this->assertLessThanOrEqual(100, mb_strlen($text));
    }
}
