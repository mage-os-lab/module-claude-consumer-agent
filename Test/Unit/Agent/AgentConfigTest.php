<?php
declare(strict_types=1);

namespace MageOS\ClaudeConsumerAgent\Test\Unit\Agent;

use MageOS\ClaudeConsumerAgent\Model\Agent\AgentConfig;
use PHPUnit\Framework\TestCase;

final class AgentConfigTest extends TestCase
{
    public function testDefaultsMatchConfigXmlDefaults(): void
    {
        $config = new AgentConfig();
        $this->assertSame('claude-sonnet-5', $config->modelId);
        $this->assertSame(2048, $config->maxTokens);
        $this->assertSame('low', $config->thinkingEffort);
        $this->assertSame(120, $config->requestTimeout);
        $this->assertSame(10, $config->connectTimeout);
        $this->assertSame('', $config->brandName);
        $this->assertSame('the shopping assistant', $config->assistantName);
        $this->assertSame('warm, concise, and plain about trade-offs', $config->brandVoice);
        $this->assertSame(
            'Hi. Tell me what you are looking for and I will find it.',
            $config->greeting
        );
        $this->assertSame(
            [
                'Find something for a gift',
                'Compare two products',
                'What is your return policy?',
                'Where is my order?',
                'Show me what is new',
            ],
            $config->starters
        );
        $this->assertSame([], $config->policyPages);
        $this->assertSame([], $config->allowedCategories);
        $this->assertSame([], $config->storeFacts);
        $this->assertTrue($config->includeCoreFacts);
        $this->assertSame(4, $config->concurrentTurns);
        $this->assertSame(40, $config->turnsPerSession);
        $this->assertSame(12, $config->turnsPerSessionWindow);
        $this->assertSame(10, $config->turnsPerIpMinute);
        $this->assertSame(8, $config->maxToolIterations);
        $this->assertSame(24, $config->maxQuantityPerItem);
        $this->assertSame(100, $config->maxCartLines);
        $this->assertSame(2000, $config->maxMessageLength);
        $this->assertSame(90, $config->turnWallClock);
        $this->assertSame(8, $config->maxSearchResults);
        $this->assertSame(12000, $config->maxFencedChars);
        $this->assertSame(100000, $config->compactAboveTokens);
        $this->assertSame('auto', $config->streaming);
        $this->assertSame(4, $config->firstByteThreshold);
        $this->assertSame(10, $config->heartbeatSeconds);
        $this->assertSame(30, $config->retentionDays);
        $this->assertTrue($config->showAiLabel);
        $this->assertSame('', $config->contactUrl);
        $this->assertSame('Contact us', $config->contactLabel);
        $this->assertFalse($config->debugLog);
        $this->assertFalse($config->enabled);
        $this->assertSame('overlay', $config->surfaceMode);
        $this->assertTrue($config->launcherEnabled);
        $this->assertTrue($config->keepOpen);
        $this->assertTrue($config->productBlockEnabled);
        $this->assertSame('cart', $config->headerIconView);
        $this->assertSame(
            [
                'image' => true,
                'price' => true,
                'description' => true,
                'stock' => true,
                'addToCart' => true,
                'reason' => true,
            ],
            $config->productCard
        );
    }

    public function testCapabilityDefaults(): void
    {
        $config = new AgentConfig();
        $this->assertTrue($config->enableCart);
        $this->assertTrue($config->enableOrders);
        $this->assertTrue($config->enableFulfillment);
        $this->assertTrue($config->closeOnPresentation);
        $this->assertFalse($config->enableMemory);
    }

    public function testEnablePoliciesDefaultsFalseWhenPolicyPagesEmpty(): void
    {
        $config = new AgentConfig(policyPages: []);
        $this->assertFalse($config->enablePolicies);
    }

    public function testEnablePoliciesDefaultsTrueWhenPolicyPagesNotEmpty(): void
    {
        $config = new AgentConfig(policyPages: ['returns-policy']);
        $this->assertTrue($config->enablePolicies);
    }

    public function testEnablePoliciesExplicitOverridesComputedDefault(): void
    {
        $config = new AgentConfig(policyPages: ['returns-policy'], enablePolicies: false);
        $this->assertFalse($config->enablePolicies);

        $config = new AgentConfig(policyPages: [], enablePolicies: true);
        $this->assertTrue($config->enablePolicies);
    }

    public function testEnablePoliciesDefaultsTrueWhenAStoreFactUsesACmsPage(): void
    {
        $config = new AgentConfig(storeFacts: [
            ['topic' => 'Returns', 'keywords' => ['return'], 'source' => 'cms_page', 'value' => 'returns'],
        ]);
        $this->assertTrue($config->enablePolicies);
    }

    public function testEnablePoliciesDefaultsTrueWhenAStoreFactUsesACmsBlock(): void
    {
        $config = new AgentConfig(storeFacts: [
            ['topic' => 'Care', 'keywords' => ['care'], 'source' => 'cms_block', 'value' => 'care-guide'],
        ]);
        $this->assertTrue($config->enablePolicies);
    }

    public function testEnablePoliciesDefaultsFalseWhenStoreFactsHaveNoCmsSource(): void
    {
        $config = new AgentConfig(storeFacts: [
            ['topic' => 'Price match', 'keywords' => ['price match'], 'source' => 'text', 'value' => 'We match.'],
            ['topic' => 'Gift wrapping', 'keywords' => ['gift wrap'], 'source' => 'not_offered', 'value' => ''],
        ]);
        $this->assertFalse($config->enablePolicies);
    }

    public function testStoreFactsAndIncludeCoreFactsDefaults(): void
    {
        $config = new AgentConfig();
        $this->assertSame([], $config->storeFacts);
        $this->assertTrue($config->includeCoreFacts);
    }

    public function testPromptBearingFields(): void
    {
        $config = new AgentConfig(
            brandName: 'Acme',
            assistantName: 'Ava',
            brandVoice: 'friendly',
            domainSearchNotes: 'notes',
            modelId: 'claude-sonnet-5'
        );
        $this->assertSame(
            [
                'brandName' => 'Acme',
                'assistantName' => 'Ava',
                'brandVoice' => 'friendly',
                'domainSearchNotes' => 'notes',
                'enableCart' => true,
                'enableOrders' => true,
                'enablePolicies' => false,
                'enableFulfillment' => true,
                'enableMemory' => false,
                'modelId' => 'claude-sonnet-5',
                'catalogMapDepth' => 2,
                'catalogMapRoots' => [],
                'catalogMapMaxChars' => 6000,
                'storeFacts' => [],
                'includeCoreFacts' => true,
            ],
            $config->promptBearingFields()
        );
    }
}
