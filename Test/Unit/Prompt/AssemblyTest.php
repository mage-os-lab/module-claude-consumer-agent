<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Prompt;

use MageOS\AiShoppingAssistant\Model\Agent\Prompt\Assembly;
use PHPUnit\Framework\TestCase;

final class AssemblyTest extends TestCase
{
    private function tools(): array
    {
        return [
            ['name' => 'search_products', 'input_schema' => ['type' => 'object']],
            ['name' => 'get_cart', 'input_schema' => ['type' => 'object']],
        ];
    }

    private function grownConversation(): array
    {
        return [
            ['role' => 'user', 'content' => 'show me tents'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Here are two.']]],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => 'ok'],
                    ['type' => 'tool_result', 'tool_use_id' => 'tu-2', 'content' => 'ok'],
                ],
            ],
        ];
    }

    public function testStaticSystemBlockCarriesTheCacheBreakpoint(): void
    {
        $assembly = new Assembly();
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static text',
            'dynamic text',
            $this->tools(),
            ['type' => 'auto'],
            [['role' => 'user', 'content' => 'hello']],
            true,
            ['type' => 'disabled'],
            'low'
        );
        $this->assertSame(
            ['type' => 'text', 'text' => 'static text', 'cache_control' => ['type' => 'ephemeral']],
            $request['system'][0]
        );
        $this->assertSame(['type' => 'text', 'text' => 'dynamic text'], $request['system'][1]);
    }

    public function testLastToolDefinitionCarriesTheMarkerAndOthersDoNot(): void
    {
        $assembly = new Assembly();
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            [],
            true,
            ['type' => 'disabled'],
            'low'
        );
        $this->assertArrayNotHasKey('cache_control', $request['tools'][0]);
        $this->assertSame(['type' => 'ephemeral'], $request['tools'][1]['cache_control']);
    }

    public function testRollingMarkerOnAutoRoundWithTwoOrMoreMessagesLandsOnNewestBlock(): void
    {
        $assembly = new Assembly();
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            $this->grownConversation(),
            true,
            ['type' => 'disabled'],
            'low'
        );
        $messages = $request['messages'];
        $this->assertCount(3, $messages);
        $lastContent = $messages[2]['content'];
        $this->assertSame(['type' => 'ephemeral'], $lastContent[1]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $lastContent[0]);
        $this->assertArrayNotHasKey('cache_control', $messages[1]['content'][0]);
    }

    public function testRollingMarkerNeverAppliedOnForcedRounds(): void
    {
        $assembly = new Assembly();
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'tool', 'name' => 'search_products'],
            $this->grownConversation(),
            false,
            ['type' => 'disabled'],
            'low'
        );
        foreach ($request['messages'] as $message) {
            $content = is_array($message['content']) ? $message['content'] : [];
            foreach ($content as $block) {
                if (is_array($block)) {
                    $this->assertArrayNotHasKey('cache_control', $block);
                }
            }
        }
    }

    public function testBareFirstCallOfAOneMessageConversationIsUnmarked(): void
    {
        $assembly = new Assembly();
        $messages = [['role' => 'user', 'content' => 'hello']];
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            $messages,
            true,
            ['type' => 'disabled'],
            'low'
        );
        $this->assertSame($messages, $request['messages']);
    }

    public function testConsecutiveUserMessagesMergeToolResultBlocksFirst(): void
    {
        $assembly = new Assembly();
        $messages = array_merge(
            $this->grownConversation(),
            [['role' => 'user', 'content' => 'the cheaper one']]
        );
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            $messages,
            true,
            ['type' => 'disabled'],
            'low'
        );
        $this->assertCount(3, $request['messages']);
        $content = $request['messages'][2]['content'];
        $this->assertSame(['tool_result', 'tool_result', 'text'], array_column($content, 'type'));
        $this->assertSame('the cheaper one', $content[2]['text']);
        $this->assertSame(['type' => 'ephemeral'], $content[2]['cache_control']);
    }

    public function testIncomingCacheControlIsStrippedBeforeTheRollingMarkerIsPlaced(): void
    {
        $assembly = new Assembly();
        $alreadyMarked = $this->grownConversation();
        $alreadyMarked[2]['content'][1]['cache_control'] = ['type' => 'ephemeral'];
        $messages = array_merge(
            $alreadyMarked,
            [
                ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'The first is lighter.']]],
                ['role' => 'user', 'content' => 'the cheaper one'],
            ]
        );
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            $messages,
            true,
            ['type' => 'disabled'],
            'low'
        );
        $marked = [];
        foreach ($request['messages'] as $message) {
            $content = is_array($message['content']) ? $message['content'] : [];
            foreach ($content as $block) {
                if (is_array($block) && array_key_exists('cache_control', $block)) {
                    $marked[] = $block;
                }
            }
        }
        $this->assertCount(1, $marked);
        $this->assertSame('the cheaper one', $marked[0]['text']);
    }

    public function testOutputConfigAddedOnlyForAdaptiveThinking(): void
    {
        $assembly = new Assembly();
        $withAdaptive = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            [],
            true,
            ['type' => 'adaptive'],
            'high'
        );
        $this->assertSame(['effort' => 'high'], $withAdaptive['output_config']);

        $withoutAdaptive = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            [],
            true,
            ['type' => 'disabled'],
            'high'
        );
        $this->assertArrayNotHasKey('output_config', $withoutAdaptive);
    }

    public function testEmptyMessagesIsANoop(): void
    {
        $assembly = new Assembly();
        $request = $assembly->build(
            'claude-sonnet-5',
            2048,
            'static',
            'dynamic',
            $this->tools(),
            ['type' => 'auto'],
            [],
            true,
            ['type' => 'disabled'],
            'low'
        );
        $this->assertSame([], $request['messages']);
    }
}
