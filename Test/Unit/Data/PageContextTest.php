<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

class PageContextTest extends TestCase
{
    public function testDefaults(): void
    {
        $pageContext = new PageContext();

        $this->assertSame('home', $pageContext->getPageType());
        $this->assertNull($pageContext->getProductId());
        $this->assertNull($pageContext->getProductName());
        $this->assertNull($pageContext->getQuery());
        $this->assertNull($pageContext->getCategoryId());
        $this->assertNull($pageContext->getCategoryName());
    }

    public function testFromArrayDropsUnknownKeys(): void
    {
        $pageContext = PageContext::fromArray([
            'page_type' => 'product',
            'product_id' => '1042',
            'query' => null,
            'unexpected' => 'value',
            'extra' => ['nested' => true],
        ]);

        $this->assertSame(
            [
                'page_type' => 'product',
                'product_id' => '1042',
                'product_name' => null,
                'query' => null,
                'category_id' => null,
                'category_name' => null,
            ],
            $pageContext->toArray()
        );
    }

    public function testFromArrayMapsBadPageTypeToOther(): void
    {
        $pageContext = PageContext::fromArray(['page_type' => 'not_a_real_page']);

        $this->assertSame('other', $pageContext->getPageType());
    }

    public function testFromArrayKeepsProductIdAndQueryAsStringsOrNull(): void
    {
        $pageContext = PageContext::fromArray([
            'page_type' => 'search',
            'product_id' => 1042,
            'query' => 'blue widget',
        ]);

        $this->assertSame('1042', $pageContext->getProductId());
        $this->assertSame('blue widget', $pageContext->getQuery());

        $emptyPageContext = PageContext::fromArray(['page_type' => 'cart']);

        $this->assertNull($emptyPageContext->getProductId());
        $this->assertNull($emptyPageContext->getQuery());
    }

    public function testFromArrayAcceptsEveryValidPageType(): void
    {
        foreach (['home', 'search', 'product', 'category', 'cart', 'orders', 'other'] as $pageType) {
            $pageContext = PageContext::fromArray(['page_type' => $pageType]);

            $this->assertSame($pageType, $pageContext->getPageType());
        }
    }

    public function testFromArrayReadsCategoryIdAndName(): void
    {
        $pageContext = PageContext::fromArray([
            'page_type' => 'category',
            'category_id' => '1050',
            'category_name' => 'Rugs',
        ]);

        $this->assertSame('1050', $pageContext->getCategoryId());
        $this->assertSame('Rugs', $pageContext->getCategoryName());
    }

    public function testFromArrayCastsNumericCategoryIdToString(): void
    {
        $pageContext = PageContext::fromArray(['category_id' => 1050]);

        $this->assertSame('1050', $pageContext->getCategoryId());
    }

    public function testFromArrayCategoryIdIsNullWhenNotNumeric(): void
    {
        $pageContext = PageContext::fromArray(['category_id' => 'not-a-number']);

        $this->assertNull($pageContext->getCategoryId());
    }

    public function testFromArrayCategoryNameIsTrimmedAndCappedAt120Chars(): void
    {
        $pageContext = PageContext::fromArray(['category_name' => '  ' . str_repeat('a', 300) . '  ']);

        $this->assertSame(str_repeat('a', 120), $pageContext->getCategoryName());
    }

    public function testFromArrayQueryIsTrimmedAndCappedAt200Chars(): void
    {
        $pageContext = PageContext::fromArray(['page_type' => 'search', 'query' => '  ' . str_repeat('q', 300) . '  ']);

        $this->assertSame(str_repeat('q', 200), $pageContext->getQuery());
    }

    public function testFromArrayQueryIsNullWhenBlankOrNotAString(): void
    {
        $this->assertNull(PageContext::fromArray(['query' => '   '])->getQuery());
        $this->assertNull(PageContext::fromArray(['query' => ['lamp']])->getQuery());
        $this->assertNull(PageContext::fromArray(['query' => 42])->getQuery());
    }

    public function testFromArrayCategoryNameIsNullWhenBlankAfterTrim(): void
    {
        $pageContext = PageContext::fromArray(['category_name' => '   ']);

        $this->assertNull($pageContext->getCategoryName());
    }

    public function testFromArrayCategoryFieldsDefaultToNullWhenAbsent(): void
    {
        $pageContext = PageContext::fromArray(['page_type' => 'cart']);

        $this->assertNull($pageContext->getCategoryId());
        $this->assertNull($pageContext->getCategoryName());
    }

    public function testToArrayCarriesCategoryIdAndName(): void
    {
        $pageContext = PageContext::fromArray([
            'page_type' => 'category',
            'category_id' => 1050,
            'category_name' => 'Rugs',
        ]);

        $this->assertSame(
            [
                'page_type' => 'category',
                'product_id' => null,
                'product_name' => null,
                'query' => null,
                'category_id' => '1050',
                'category_name' => 'Rugs',
            ],
            $pageContext->toArray()
        );
    }

    public function testFromArrayReadsProductName(): void
    {
        $pageContext = PageContext::fromArray([
            'page_type' => 'product',
            'product_id' => '29335',
            'product_name' => 'The Interior Design Handbook',
        ]);

        $this->assertSame('The Interior Design Handbook', $pageContext->getProductName());
    }

    public function testFromArrayProductNameIsTrimmedAndCappedAt120Chars(): void
    {
        $pageContext = PageContext::fromArray(['product_name' => '  ' . str_repeat('a', 300) . '  ']);

        $this->assertSame(str_repeat('a', 120), $pageContext->getProductName());
    }

    public function testFromArrayProductNameIsNullWhenBlankAfterTrim(): void
    {
        $pageContext = PageContext::fromArray(['product_name' => '   ']);

        $this->assertNull($pageContext->getProductName());
    }

    public function testFromArrayProductNameDefaultsToNullWhenAbsent(): void
    {
        $pageContext = PageContext::fromArray(['page_type' => 'product', 'product_id' => '1042']);

        $this->assertNull($pageContext->getProductName());
    }
}
