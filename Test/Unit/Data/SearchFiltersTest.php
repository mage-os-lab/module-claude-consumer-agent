<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\SearchFilters;
use PHPUnit\Framework\TestCase;

final class SearchFiltersTest extends TestCase
{
    public function testFromArrayReadsCategoryId(): void
    {
        $filters = SearchFilters::fromArray(['category_id' => '175']);
        $this->assertSame(175, $filters->getCategoryId());
    }

    public function testCategoryIdDefaultsToNullWhenAbsent(): void
    {
        $filters = SearchFilters::fromArray([]);
        $this->assertNull($filters->getCategoryId());
    }

    public function testToArrayCarriesCategoryId(): void
    {
        $filters = SearchFilters::fromArray(['category_id' => 175]);
        $this->assertSame(175, $filters->toArray()['category_id']);
    }

    public function testFromArrayAcceptsBestSellersSort(): void
    {
        $filters = SearchFilters::fromArray(['sort' => 'best_sellers']);
        $this->assertSame('best_sellers', $filters->getSort());
        $this->assertSame('best_sellers', $filters->toArray()['sort']);
    }
}
