<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Console;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Backend\CatalogMapProviderInterface;
use MageOS\AiShoppingAssistant\Api\Backend\SkuMatcherInterface;
use MageOS\AiShoppingAssistant\Api\Backend\StoreFactTitleResolverInterface;
use MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Console\Command\EvalRun;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Options;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\Grounding\SkuCandidates;
use MageOS\AiShoppingAssistant\Model\Eval\Toolkit;
use MageOS\AiShoppingAssistant\Model\Agent\Lexicon;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry as PresentationRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CatalogMap;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\CoreFacts;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\StoreFactsBlock;
use MageOS\AiShoppingAssistant\Model\Agent\Schema\Validator;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\FrontMatter;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Loader;
use MageOS\AiShoppingAssistant\Model\Agent\Skill\Registry as SkillRegistry;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;

class EvalRunTest extends TestCase
{
    private array $tempCaseDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempCaseDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->tempCaseDirs = [];
    }

    public function testProductsOptionValuesKeyPassesWhenEveryShownItemMatches(): void
    {
        $row = $this->runSingleCase(
            [
                ['name' => 'present_products', 'input' => ['picks' => [['product_id' => 'TS-1', 'option_values' => ['Color' => 'Blue']]]]],
                ['name' => 'present_suggestions', 'input' => ['suggestions' => ['Pick a size']]],
            ],
            ['products_option_values' => ['color' => ' blue ']]
        );

        $this->assertTrue($row['pass'], implode(', ', $row['failed']));
    }

    public function testProductsOptionValuesKeyFailsWhenAShownItemDoesNotMatch(): void
    {
        $row = $this->runSingleCase(
            [
                ['name' => 'present_products', 'input' => ['picks' => [['product_id' => 'TS-1']]]],
                ['name' => 'present_suggestions', 'input' => ['suggestions' => ['Pick a size']]],
            ],
            ['products_option_values' => ['Color' => 'Blue']]
        );

        $this->assertFalse($row['pass']);
        $this->assertSame(['products_option_values'], $row['failed']);
    }

    public function testProductsOptionValuesKeyFailsWhenNoProductsCardWasShown(): void
    {
        $row = $this->runSingleCase(
            [
                ['name' => 'present_suggestions', 'input' => ['suggestions' => ['Show blue tees']]],
            ],
            ['products_option_values' => ['Color' => 'Blue']]
        );

        $this->assertFalse($row['pass']);
        $this->assertSame(['products_option_values'], $row['failed']);
    }

    public function testProductsOptionValuesKeyFailsWhenTheExpectedMapIsEmpty(): void
    {
        $row = $this->runSingleCase(
            [
                ['name' => 'present_products', 'input' => ['picks' => [['product_id' => 'TS-1', 'option_values' => ['Color' => 'Blue']]]]],
                ['name' => 'present_suggestions', 'input' => ['suggestions' => ['Pick a size']]],
            ],
            ['products_option_values' => new \stdClass()]
        );

        $this->assertFalse($row['pass']);
        $this->assertSame(['products_option_values'], $row['failed']);
    }

    public function testProductsOptionValuesKeyFailsWhenNoItemWasChecked(): void
    {
        $method = new ReflectionMethod(EvalRun::class, 'productsMatchOptionValues');

        $result = $method->invoke(
            $this->buildCommand(),
            ['Color' => 'Blue'],
            [['layout' => 'grid'], ['layout' => 'list', 'items' => []]]
        );

        $this->assertFalse($result);
    }

    public function testBrandSpreadKeysPassWhenPicksSpanDistinctBrandsWithinCap(): void
    {
        $row = $this->runBrandCase(
            [
                ['product_id' => 'BR-1', 'title' => 'Chair One', 'price' => 100.0, 'brand' => 'Steelform'],
                ['product_id' => 'BR-2', 'title' => 'Chair Two', 'price' => 120.0, 'brand' => 'Aerodesk'],
                ['product_id' => 'BR-3', 'title' => 'Chair Three', 'price' => 140.0, 'brand' => 'Kestrel'],
            ],
            ['BR-1', 'BR-2', 'BR-3'],
            ['products_min_distinct_brands' => 3, 'products_max_brand_share' => 1]
        );

        $this->assertTrue($row['pass'], implode(', ', $row['failed']));
    }

    public function testBrandSpreadKeysFailWhenAllPicksShareOneBrand(): void
    {
        $row = $this->runBrandCase(
            [
                ['product_id' => 'BR-1', 'title' => 'Chair One', 'price' => 100.0, 'brand' => 'Steelform'],
                ['product_id' => 'BR-2', 'title' => 'Chair Two', 'price' => 120.0, 'brand' => 'Steelform'],
                ['product_id' => 'BR-3', 'title' => 'Chair Three', 'price' => 140.0, 'brand' => 'Steelform'],
            ],
            ['BR-1', 'BR-2', 'BR-3'],
            ['products_min_distinct_brands' => 2, 'products_max_brand_share' => 1]
        );

        $this->assertFalse($row['pass']);
        $this->assertSame(['products_min_distinct_brands', 'products_max_brand_share'], $row['failed']);
    }

    public function testBrandSpreadKeysIgnorePicksWithNoBrandRecord(): void
    {
        $row = $this->runBrandCase(
            [
                ['product_id' => 'BR-1', 'title' => 'Chair One', 'price' => 100.0, 'brand' => 'Steelform'],
                ['product_id' => 'BR-2', 'title' => 'Chair Two', 'price' => 120.0, 'brand' => 'Aerodesk'],
                ['product_id' => 'BR-3', 'title' => 'Chair Three', 'price' => 140.0],
            ],
            ['BR-1', 'BR-2', 'BR-3'],
            ['products_min_distinct_brands' => 2, 'products_max_brand_share' => 1]
        );

        $this->assertTrue($row['pass'], implode(', ', $row['failed']));
    }

    public function testTableMarksARubricOnlyCaseAsPassManualNotAPlainPass(): void
    {
        $case = [
            'id' => 'rubric-only',
            'priority' => 'low',
            'state' => [],
            'turns' => ['Say hello'],
            'rounds' => [[['text' => 'Hello there.']]],
            'expected' => ['rubric' => 'A human judges the tone of the reply.'],
        ];
        $dir = sys_get_temp_dir() . '/aiagent-eval-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempCaseDirs[] = $dir;
        file_put_contents($dir . '/rubric-only.json', (string)json_encode($case));

        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--cases' => $dir]);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('PASS (manual)', $display);
        $this->assertDoesNotMatchRegularExpression('/\|\s*PASS\s*\|/', $display);
    }

    public function testAllShippedCasesPassInFixtureMode(): void
    {
        $tester = new CommandTester($this->buildCommand());

        $exitCode = $tester->execute(['--cases' => $this->casesDir()]);
        $display = $tester->getDisplay();

        $this->assertSame(0, $exitCode, $display);
        foreach ($this->caseIds() as $id) {
            $this->assertMatchesRegularExpression('/' . preg_quote($id, '/') . '\s*\|[^|]*\|\s*PASS/', $display);
        }
        $this->assertStringNotContainsString('FAIL', $display);
    }

    public function testJsonOutputReportsEveryCaseAsAnObject(): void
    {
        $tester = new CommandTester($this->buildCommand());

        $tester->execute(['--cases' => $this->casesDir(), '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($decoded);
        $this->assertCount(count($this->caseIds()), $decoded);
        foreach ($decoded as $row) {
            $this->assertTrue($row['pass'], $row['id'] . ' failed: ' . implode(', ', $row['failed']));
        }
    }

    public function testFilterOptionNarrowsToMatchingCaseId(): void
    {
        $tester = new CommandTester($this->buildCommand());

        $tester->execute(['--cases' => $this->casesDir(), '--filter' => '001-*', '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertCount(1, $decoded);
        $this->assertSame('001-policy-grounding', $decoded[0]['id']);
    }

    /**
     * @return string[]
     */
    private function caseIds(): array
    {
        $files = glob($this->casesDir() . '/*.json');
        $files = $files !== false ? $files : [];
        sort($files);
        return array_map(static fn (string $file): string => basename($file, '.json'), $files);
    }

    private function casesDir(): string
    {
        return dirname(__DIR__, 2) . '/Eval/cases';
    }

    private function runSingleCase(array $tools, array $expected): array
    {
        $variant = static fn (string $id, string $size, string $color): array => [
            'product_id' => $id,
            'title' => 'Tee - ' . $size . ' ' . $color,
            'price' => 22.0,
            'currency' => 'USD',
            'in_stock' => true,
            'variant_of' => 'TS-1',
            'option_values' => ['Size' => $size, 'Color' => $color],
        ];
        $case = [
            'id' => 'products-option-values',
            'priority' => 'low',
            'state' => [
                'seen_products' => [
                    [
                        'product_id' => 'TS-1',
                        'title' => 'Tee',
                        'price' => 22.0,
                        'currency' => 'USD',
                        'in_stock' => true,
                        'options' => ['Size' => ['S', 'M'], 'Color' => ['Blue', 'Orange']],
                    ],
                    $variant('TS-1-S-BLUE', 'S', 'Blue'),
                    $variant('TS-1-S-ORANGE', 'S', 'Orange'),
                    $variant('TS-1-M-BLUE', 'M', 'Blue'),
                ],
            ],
            'turns' => ['Show me the tee in blue'],
            'rounds' => [[['text' => 'Here is the tee.', 'tools' => $tools]]],
            'expected' => $expected,
        ];
        $dir = sys_get_temp_dir() . '/aiagent-eval-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempCaseDirs[] = $dir;
        file_put_contents($dir . '/products-option-values.json', (string)json_encode($case));

        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--cases' => $dir, '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($decoded, $tester->getDisplay());
        $this->assertCount(1, $decoded);
        return $decoded[0];
    }

    private function runBrandCase(array $catalog, array $pickIds, array $expected): array
    {
        $picks = array_map(
            static fn (string $productId): array => ['product_id' => $productId, 'reason' => 'A pick.'],
            $pickIds
        );
        $tools = [
            ['name' => 'present_products', 'input' => ['picks' => $picks]],
            ['name' => 'present_suggestions', 'input' => ['suggestions' => ['Narrow it down']]],
        ];
        $case = [
            'id' => 'brand-spread',
            'priority' => 'low',
            'state' => ['seen_products' => $catalog],
            'turns' => ['Show me some chairs'],
            'rounds' => [[['text' => 'Here are a few options.', 'tools' => $tools]]],
            'expected' => $expected,
        ];
        $dir = sys_get_temp_dir() . '/aiagent-eval-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->tempCaseDirs[] = $dir;
        file_put_contents($dir . '/brand-spread.json', (string)json_encode($case));

        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--cases' => $dir, '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);

        $this->assertIsArray($decoded, $tester->getDisplay());
        $this->assertCount(1, $decoded);
        return $decoded[0];
    }

    private function passthroughTitleResolver(): StoreFactTitleResolverInterface
    {
        $resolver = $this->createMock(StoreFactTitleResolverInterface::class);
        $resolver->method('resolve')->willReturnArgument(1);
        return $resolver;
    }

    private function buildCommand(): EvalRun
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            static fn (string $type, array $arguments = []) => new $type(...$arguments)
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);

        $sanitizer = new Sanitizer();
        $fence = new Fence($sanitizer);
        $serializer = new Serializer($fence);
        $validator = new Validator();
        $provenance = new Provenance();
        $options = new Options($sanitizer);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $skillLoader = new Loader(
            [],
            $this->createMock(ComponentRegistrarInterface::class),
            $this->createMock(File::class),
            new FrontMatter()
        );
        $skillRegistry = new SkillRegistry($skillLoader);
        $staticSystem = new StaticSystem(
            $storeConfig,
            $skillRegistry,
            $cache,
            new Json(),
            $fence,
            $this->createMock(CatalogMapProviderInterface::class),
            new CatalogMap(),
            new CoreFacts([]),
            new StoreFactsBlock(),
            $this->passthroughTitleResolver()
        );
        $dynamicContext = new DynamicContext($fence);
        $assembly = new Assembly();
        $lexicon = new Lexicon($storeConfig);
        $streamedRoundFactory = new StreamedRoundFactory();

        $presentationRegistry = new PresentationRegistry(
            new Products($this->createMock(LoggerInterface::class), $sanitizer),
            new Comparison(),
            new OrderStatus($serializer),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions($sanitizer)
        );

        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        $adapter = $this->createMock(AdapterInterface::class);
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($adapter);

        $logger = $this->createMock(LoggerInterface::class);
        $liveClient = $this->createMock(MessagesClientInterface::class);
        $liveBackend = $this->createMock(StorefrontBackendInterface::class);
        $cartManagement = $this->createMock(CartManagementInterface::class);
        $appState = $this->createMock(State::class);
        $appEmulation = $this->createMock(Emulation::class);

        return new EvalRun(
            $objectManager,
            $storeManager,
            new Toolkit(
                $storeConfig,
                $staticSystem,
                $dynamicContext,
                $assembly,
                $lexicon,
                new SkuCandidates(),
                $streamedRoundFactory,
                $validator,
                $provenance,
                $options,
                $sanitizer,
                $fence,
                $serializer,
                $skillRegistry,
                $presentationRegistry
            ),
            $this->createMock(SkuMatcherInterface::class),
            $lockManager,
            $resourceConnection,
            $logger,
            $liveClient,
            $liveBackend,
            $cartManagement,
            $appState,
            $appEmulation
        );
    }
}
