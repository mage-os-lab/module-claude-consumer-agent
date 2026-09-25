<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Grounding;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\Rules;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use MageOS\AiShoppingAssistant\Model\Agent\Lexicon;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    private StoreConfig $storeConfig;
    private array $receivedCandidates = [];

    protected function setUp(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->storeConfig = new StoreConfig($scopeConfig, $storeManager);
    }

    private function defaultConfig(): AgentConfig
    {
        return new AgentConfig(enablePolicies: true);
    }

    private function forced(
        string $text,
        ?AgentConfig $config = null,
        ?SessionState $state = null,
        ?PageContext $page = null,
        bool $isFirstTurn = false,
        ?string $sku = null
    ): ?string {
        $rules = new Rules(new Lexicon($this->storeConfig), new SkuCandidates(), $this->matcherReturning($sku));
        return $rules->firstForcedTool(
            $config ?? $this->defaultConfig(),
            $text,
            $state ?? new SessionState(),
            $page ?? new PageContext(),
            $isFirstTurn,
            1
        );
    }

    private function matcherReturning(?string $sku): SkuMatcherInterface
    {
        $record = function (array $skus): void {
            $this->receivedCandidates = $skus;
        };
        return new class ($sku, $record) implements SkuMatcherInterface {
            public function __construct(
                private readonly ?string $sku,
                private readonly \Closure $record
            ) {
            }

            public function firstExisting(array $skus, int $storeId): ?string
            {
                ($this->record)($skus);
                return $this->sku;
            }
        };
    }

    public function testPolicyTermAndCueForceSearchPolicies(): void
    {
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?'));
        $this->assertSame('search_policies', $this->forced('Is there a restocking fee if I send it back?'));
    }

    public function testOrderTermAndCueForceGetOrders(): void
    {
        $this->assertSame('get_orders', $this->forced("Where's my order?"));
        $this->assertSame('get_orders', $this->forced("Just cancel the dog bed order, I'm done waiting."));
    }

    public function testATokenMatchingAnExistingSkuForcesGetProductDetails(): void
    {
        $this->assertSame('get_product_details', $this->forced('do you have 24-MB01?', sku: '24-MB01'));
        $this->assertSame(['24-MB01'], $this->receivedCandidates);
    }

    public function testASkuTheSessionAlreadySawIsNotReRead(): void
    {
        $state = new SessionState();
        $state->rememberProducts([['product_id' => '24-MB01', 'title' => 'Joust Duffle Bag', 'price' => 34.0]]);
        $this->assertNull($this->forced('do you have 24-MB01?', null, $state, sku: '24-MB01'));
        $this->assertNull($this->forced('do you have 24-mb01?', null, $state, sku: '24-mb01'));
        $this->assertSame('get_product_details', $this->forced('do you have 24-MB02?', null, $state, sku: '24-MB02'));
    }

    public function testTextWithoutAnExistingSkuIsNotForced(): void
    {
        $this->assertNull($this->forced('I have 2 kids at home'));
        $this->assertSame([], $this->receivedCandidates);
        $this->assertNull($this->forced('Add AR-1602 to my cart.'));
        $this->assertSame(['AR-1602'], $this->receivedCandidates);
    }

    public function testWholeWordMatchingIgnoresSubstringHits(): void
    {
        $this->assertNull($this->forced('show me lightweight tents under $200'));
        $this->assertNull($this->forced('let us return to the tent options'));
        $this->assertNull($this->forced('add two of the camp mugs to my cart'));
    }

    public function testPrecedenceRunsPolicyThenOrdersThenCatalog(): void
    {
        $this->assertSame('search_policies', $this->forced('Can I return my order?', sku: 'AR-1602'));
        $this->assertSame('get_orders', $this->forced("What's the status of my order for AR-1602?", sku: 'AR-1602'));
    }

    public function testThePolicyRuleHasAConfigSwitch(): void
    {
        $this->assertSame(
            'search_policies',
            $this->forced('How do returns work?', new AgentConfig(enablePolicies: true))
        );
        $this->assertNull($this->forced('How do returns work?', new AgentConfig(enablePolicies: false)));
    }

    public function testTheOrderRuleHasAConfigSwitch(): void
    {
        $this->assertSame('get_orders', $this->forced("Where's my order?", new AgentConfig(enableOrders: true)));
        $this->assertNull($this->forced("Where's my order?", new AgentConfig(enableOrders: false)));
    }

    public function testProductPageFirstTurnForcesGetProductDetailsWhenUnseen(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $this->assertSame(
            'get_product_details',
            $this->forced('hello there', null, null, $page, true)
        );
    }

    public function testProductPageIsNotForcedOnALaterTurn(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $this->assertNull($this->forced('hello there', null, null, $page, false));
    }

    public function testProductPageIsNotForcedWhenAlreadySeen(): void
    {
        $page = PageContext::fromArray(['page_type' => 'product', 'product_id' => 'AR-9000']);
        $state = new SessionState();
        $state->rememberProducts([['product_id' => 'AR-9000', 'title' => 'Lamp', 'price' => 20.0]]);
        $this->assertNull($this->forced('hello there', null, $state, $page, true));
    }

    public function testTextFactKeywordSuppressesSearchPolicies(): void
    {
        $config = new AgentConfig(
            enablePolicies: true,
            storeFacts: [
                [
                    'topic' => 'Price match',
                    'keywords' => ['price match'],
                    'source' => 'text',
                    'value' => 'We match any advertised Canadian price.',
                ],
            ]
        );
        $this->assertNull($this->forced('Do you price match?', $config));
    }

    public function testFactWithoutKeywordsMatchesOnItsTopic(): void
    {
        $config = new AgentConfig(
            enablePolicies: true,
            storeFacts: [
                ['topic' => 'Price match', 'keywords' => [], 'source' => 'text', 'value' => 'We match any price.'],
            ]
        );
        $this->assertNull($this->forced('Do you offer a PRICE MATCH on returns?', $config));
        $this->assertSame('search_policies', $this->forced('How do returns work?', $config));
    }

    public function testNotOfferedFactKeywordSuppressesGetOrders(): void
    {
        $config = new AgentConfig(
            enableOrders: true,
            storeFacts: [
                ['topic' => 'Gift message', 'keywords' => ['gift message'], 'source' => 'not_offered', 'value' => ''],
            ]
        );
        $this->assertNull($this->forced('Can I add a gift message to my order?', $config));
    }

    public function testCmsFactKeywordStillForcesSearchPolicies(): void
    {
        $config = new AgentConfig(
            enablePolicies: true,
            storeFacts: [
                [
                    'topic' => 'Returns',
                    'keywords' => ['return', 'refund', 'exchange'],
                    'source' => 'cms_page',
                    'value' => 'returns',
                ],
            ]
        );
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?', $config));
    }

    public function testNoStoreFactsLeavesTheOldBehaviour(): void
    {
        $config = new AgentConfig(enablePolicies: true, enableOrders: true, storeFacts: []);
        $this->assertSame('search_policies', $this->forced('How do returns work for opened items?', $config));
        $this->assertSame('get_orders', $this->forced("Where's my order?", $config));
    }
}
