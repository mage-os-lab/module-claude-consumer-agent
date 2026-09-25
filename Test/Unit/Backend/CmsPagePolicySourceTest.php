<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Api\Data\BlockSearchResultsInterface;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\Data\PageSearchResultsInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CmsPagePolicySource;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class CmsPagePolicySourceTest extends TestCase
{
    private function context(): SessionContext
    {
        return new SessionContext(
            'session-1',
            null,
            1,
            1,
            new PageContext(),
            new \DateTimeImmutable('now')
        );
    }

    private function storeConfig(array $policyPages, array $storeFacts = []): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($policyPages, $storeFacts): ?string {
                if ($path === 'ai_integration/aiagent/content/policy_pages') {
                    return implode(',', $policyPages);
                }
                if ($path === 'ai_integration/aiagent/content/store_facts') {
                    return $storeFacts !== [] ? (string)json_encode($storeFacts) : null;
                }
                return null;
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder&\PHPUnit\Framework\MockObject\MockObject
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        return $builder;
    }

    private function page(string $identifier, string $title, string $content): PageInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('getIdentifier')->willReturn($identifier);
        $page->method('getTitle')->willReturn($title);
        $page->method('getContent')->willReturn($content);
        return $page;
    }

    private function pageRepository(array $pages): PageRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $results = $this->createMock(PageSearchResultsInterface::class);
        $results->method('getItems')->willReturn($pages);
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);
        return $repository;
    }

    private function block(string $identifier, string $title, string $content): BlockInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $block = $this->createMock(BlockInterface::class);
        $block->method('getIdentifier')->willReturn($identifier);
        $block->method('getTitle')->willReturn($title);
        $block->method('getContent')->willReturn($content);
        return $block;
    }

    private function blockRepository(array $blocks): BlockRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $results = $this->createMock(BlockSearchResultsInterface::class);
        $results->method('getItems')->willReturn($blocks);
        $repository = $this->createMock(BlockRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);
        return $repository;
    }

    public function testChunksAtHeadingsWithCharacterCap(): void
    {
        $longParagraph = trim(str_repeat('lorem ', 400));
        $content = '<h2>Returns</h2><p>' . $longParagraph . '</p><h3>Exchanges</h3><p>exchange lorem details</p>';
        $page = $this->page('returns', 'Return Policy', $content);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save');

        $source = new CmsPagePolicySource(
            $this->pageRepository([$page]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig(['returns']),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'lorem');

        $this->assertGreaterThanOrEqual(3, count($results));
        foreach ($results as $policy) {
            $this->assertLessThanOrEqual(1500, mb_strlen($policy->getContent()));
        }
        $titles = array_map(static fn ($policy) => $policy->getTitle(), $results);
        $this->assertContains('Return Policy / Returns', $titles);
        $this->assertContains('Return Policy / Exchanges', $titles);
    }

    public function testStripsScriptStyleAndWidgetDirectivesBeforeStrippingTags(): void
    {
        $content = '<p>Please review our return policy before shipping items back.</p>'
            . '<script>var trackingArtifact = "leak";</script>'
            . '<style>.hidden-class-should-vanish{color:red;}</style>'
            . '<p>{{widget type="Magento\\Cms\\Block\\Widget\\Block" template="widget_code_should_vanish"}}</p>'
            . '<p>Contact support for more return help.</p>';
        $page = $this->page('returns', 'Return Policy', $content);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([$page]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig(['returns']),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'return');

        $this->assertNotEmpty($results);
        $combinedContent = implode(' ', array_map(static fn ($policy) => $policy->getContent(), $results));
        $this->assertStringContainsString('return policy', $combinedContent);
        $this->assertStringNotContainsString('trackingArtifact', $combinedContent);
        $this->assertStringNotContainsString('hidden-class-should-vanish', $combinedContent);
        $this->assertStringNotContainsString('widget_code_should_vanish', $combinedContent);
    }

    public function testTermOverlapScoringWeightsTitleDouble(): void
    {
        $pageA = $this->page('general', 'General Info', '<p>details about shipping options for your order</p>');
        $pageB = $this->page('shipping', 'Shipping Policy', '<p>read the following details before ordering</p>');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([$pageA, $pageB]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig(['general', 'shipping']),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'shipping');

        $this->assertCount(2, $results);
        $this->assertSame('Shipping Policy', $results[0]->getTitle());
        $this->assertSame('General Info', $results[1]->getTitle());
    }

    public function testReturnsTopTwelveResultsOnly(): void
    {
        $pages = [];
        for ($i = 1; $i <= 20; $i++) {
            $pages[] = $this->page('page' . $i, 'Alpha Topic ' . $i, '<p>alpha content number ' . $i . '</p>');
        }

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $identifiers = array_map(static fn (int $i): string => 'page' . $i, range(1, 20));
        $source = new CmsPagePolicySource(
            $this->pageRepository($pages),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig($identifiers),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'alpha');

        $this->assertCount(12, $results);
    }

    public function testCacheHitPathSkipsPageRepository(): void
    {
        $passages = [
            ['policy_id' => 'returns#1', 'title' => 'Return Policy', 'content' => 'you can return items within 30 days'],
        ];

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn((new Json())->serialize($passages));
        $cache->expects($this->never())->method('save');

        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->expects($this->never())->method('getList');

        $source = new CmsPagePolicySource(
            $repository,
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig(['returns']),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'return');

        $this->assertCount(1, $results);
        $this->assertSame('Return Policy', $results[0]->getTitle());
    }

    public function testEmptyPolicyPagesConfigReturnsNoResults(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $repository = $this->createMock(PageRepositoryInterface::class);
        $repository->expects($this->never())->method('getList');

        $source = new CmsPagePolicySource(
            $repository,
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig([]),
            $this->blockRepository([])
        );

        $this->assertSame([], $source->search($this->context(), 'anything'));
    }

    public function testStoreFilterIncludesAllStoreViewsScope(): void
    {
        $page = $this->page('returns', 'Return Policy', '<p>return within 30 days</p>');

        $calls = [];
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(
            function (string $field, $value = null, $condition = 'eq') use ($builder, &$calls): SearchCriteriaBuilder {
                $calls[] = [$field, $value, $condition];
                return $builder;
            }
        );
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([$page]),
            $builder,
            $cache,
            new Json(),
            $this->storeConfig(['returns']),
            $this->blockRepository([])
        );

        $source->search($this->context(), 'return');

        $this->assertContains(['store_id', [0, 1], 'in'], $calls);
    }

    public function testCmsBlockFactProducesAPassageWithTheFactTopicAsCategory(): void
    {
        $block = $this->block('care-guide', 'Care guide', '<p>wipe with a damp lorem cloth</p>');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig([], [
                ['topic' => 'Care instructions', 'keywords' => 'care, cleaning', 'source' => 'cms_block', 'value' => 'care-guide'],
            ]),
            $this->blockRepository([$block])
        );

        $results = $source->search($this->context(), 'lorem');

        $this->assertCount(1, $results);
        $this->assertSame('Care guide', $results[0]->getTitle());
        $this->assertSame('Care instructions', $results[0]->getCategory());
    }

    public function testCmsPageFactProducesAPassageWithTheFactTopicAsCategory(): void
    {
        $page = $this->page('returns', 'Returns and Refund Policy', '<p>return within 30 days lorem</p>');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([$page]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig([], [
                ['topic' => 'Returns', 'keywords' => 'return, refund', 'source' => 'cms_page', 'value' => 'returns'],
            ]),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'lorem');

        $this->assertCount(1, $results);
        $this->assertSame('Returns and Refund Policy', $results[0]->getTitle());
        $this->assertSame('Returns', $results[0]->getCategory());
    }

    public function testPlainPolicyPageWithoutAMatchingFactHasNoCategory(): void
    {
        $page = $this->page('returns', 'Return Policy', '<p>return within 30 days lorem</p>');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);

        $source = new CmsPagePolicySource(
            $this->pageRepository([$page]),
            $this->searchCriteriaBuilder(),
            $cache,
            new Json(),
            $this->storeConfig(['returns']),
            $this->blockRepository([])
        );

        $results = $source->search($this->context(), 'lorem');

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->getCategory());
    }
}
