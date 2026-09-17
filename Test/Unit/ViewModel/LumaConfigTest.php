<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\ViewModel;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Locale\FormatInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\ClaudeConsumerAgent\Model\Config\StoreConfig;
use MageOS\ClaudeConsumerAgent\Model\Surface\PageDetector;
use MageOS\ClaudeConsumerAgent\Model\Surface\Resolver;
use MageOS\ClaudeConsumerAgent\ViewModel\Assistant;
use MageOS\ClaudeConsumerAgent\ViewModel\CartSection;
use MageOS\ClaudeConsumerAgent\ViewModel\LumaConfig;
use PHPUnit\Framework\TestCase;

final class LumaConfigTest extends TestCase
{
    private const GREETING_PATH = 'ai_integration/aiagent/voice/greeting';
    private const STARTERS_PATH = 'ai_integration/aiagent/voice/starters';
    private const CONTACT_URL_PATH = 'ai_integration/aiagent/privacy/contact_url';

    private function buildLumaConfig(
        ?string $greeting = null,
        ?string $starters = null,
        ?string $contactUrl = null
    ): LumaConfig {
        $values = [
            self::GREETING_PATH => $greeting,
            self::STARTERS_PATH => $starters,
            self::CONTACT_URL_PATH => $contactUrl,
        ];
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $values[$path] ?? null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $store->method('getName')->willReturn('');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeConfig = new StoreConfig($scopeConfig, $storeManager);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $request = $this->createMock(Http::class);
        $request->method('getFullActionName')->willReturn('cms_index_index');
        $pageDetector = new PageDetector(
            $request,
            $this->createMock(CategoryRepositoryInterface::class),
            $this->createMock(ProductRepositoryInterface::class),
            $storeManager
        );
        $assistant = new Assistant($storeConfig, new Resolver($storeConfig, $scopeConfig), $url, $storeManager, $pageDetector);
        $localeFormat = $this->createMock(FormatInterface::class);
        $localeFormat->method('getPriceFormat')->willReturn(['pattern' => '$%s', 'requiredPrecision' => 2]);

        return new LumaConfig($assistant, new CartSection($url), $localeFormat);
    }

    public function testConfigExtendsTheAssistantSnapshotWithPriceFormatAndCartUrl(): void
    {
        $config = $this->buildLumaConfig()->config();

        $this->assertArrayHasKey('urls', $config);
        $this->assertArrayHasKey('i18n', $config);
        $this->assertSame('$%s', $config['priceFormat']['pattern']);
        $this->assertSame('/checkout/cart', $config['cartUrl']);
    }

    public function testConfigShowsAtMostFiveStarters(): void
    {
        $config = $this->buildLumaConfig(null, "One\nTwo\nThree\nFour\nFive\nSix")->config();

        $this->assertSame(['One', 'Two', 'Three', 'Four', 'Five'], $config['starters']);
    }

    public function testConfigDropsAContactUrlThatIsNotAWebMailOrPhoneLink(): void
    {
        $contactUrls = ['javascript:alert(1)', ' javascript:x', 'data:text/html,x', '//evil.example', '/\\evil.example'];
        foreach ($contactUrls as $contactUrl) {
            $config = $this->buildLumaConfig(null, null, $contactUrl)->config();

            $this->assertSame('', $config['contact']['url'], $contactUrl);
            $this->assertSame('Contact us', $config['contact']['label'], $contactUrl);
        }
    }

    public function testConfigKeepsWebMailAndPhoneContactUrls(): void
    {
        $contactUrls = ['https://example.com/contact', '/contact', 'mailto:help@example.com', 'tel:+123'];
        foreach ($contactUrls as $contactUrl) {
            $config = $this->buildLumaConfig(null, null, $contactUrl)->config();

            $this->assertSame($contactUrl, $config['contact']['url']);
        }
    }

    public function testJsonCannotCloseTheScriptTagItIsPrintedIn(): void
    {
        $greeting = '</script><img src=x onerror=alert(1)>';
        $json = $this->buildLumaConfig($greeting)->json();

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('<img', $json);
        $this->assertSame($greeting, json_decode($json, true)['greeting']);
    }

    public function testJsonStaysValidWhenAValueHoldsInvalidUtf8(): void
    {
        $json = $this->buildLumaConfig("Hi \xFF")->json();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame("Hi \u{FFFD}", $decoded['greeting']);
    }
}
