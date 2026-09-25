<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

use MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface;

final class FakeClient implements MessagesClientInterface
{
    public array $calls = [];

    public function __construct(private array $rounds = [])
    {
    }

    public function addRound(array $rawEvents): self
    {
        $this->rounds[] = $rawEvents;
        return $this;
    }

    public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
    {
        $this->calls[] = $request;
        if ($this->rounds === []) {
            throw new \LogicException('FakeClient script exhausted');
        }
        $round = array_shift($this->rounds);
        foreach ($round as $rawEvent) {
            yield $rawEvent;
        }
    }

    public static function textRound(string $text, string $stopReason = 'end_turn'): array
    {
        $messageId = 'msg_01' . substr(md5($text . $stopReason), 0, 20);
        return [
            new RawEvent('message_start', [
                'type' => 'message_start',
                'message' => [
                    'id' => $messageId,
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5',
                    'content' => [],
                    'stop_reason' => null,
                    'stop_sequence' => null,
                    'usage' => [
                        'input_tokens' => 100,
                        'cache_creation_input_tokens' => 0,
                        'cache_read_input_tokens' => 0,
                        'output_tokens' => 1,
                    ],
                ],
            ]),
            new RawEvent('content_block_start', [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]),
            new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ]),
            new RawEvent('content_block_stop', ['type' => 'content_block_stop', 'index' => 0]),
            new RawEvent('message_delta', [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => $stopReason, 'stop_sequence' => null],
                'usage' => ['output_tokens' => (int)ceil(strlen($text) / 4)],
            ]),
            new RawEvent('message_stop', ['type' => 'message_stop']),
        ];
    }

    public static function toolRound(array $toolUses, string $text = '', ?array $thinking = null): array
    {
        $messageId = 'msg_01' . substr(md5(serialize($toolUses) . $text), 0, 20);
        $events = [
            new RawEvent('message_start', [
                'type' => 'message_start',
                'message' => [
                    'id' => $messageId,
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-sonnet-5',
                    'content' => [],
                    'stop_reason' => null,
                    'stop_sequence' => null,
                    'usage' => [
                        'input_tokens' => 100,
                        'cache_creation_input_tokens' => 0,
                        'cache_read_input_tokens' => 0,
                        'output_tokens' => 1,
                    ],
                ],
            ]),
        ];

        $index = 0;
        if ($thinking !== null) {
            $events[] = new RawEvent('content_block_start', [
                'type' => 'content_block_start',
                'index' => $index,
                'content_block' => ['type' => 'thinking', 'thinking' => ''],
            ]);
            $events[] = new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $index,
                'delta' => ['type' => 'thinking_delta', 'thinking' => (string)$thinking['thinking']],
            ]);
            $events[] = new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $index,
                'delta' => ['type' => 'signature_delta', 'signature' => (string)$thinking['signature']],
            ]);
            $events[] = new RawEvent('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
            $index++;
        }
        if ($text !== '') {
            $events[] = new RawEvent('content_block_start', [
                'type' => 'content_block_start',
                'index' => $index,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]);
            $events[] = new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $index,
                'delta' => ['type' => 'text_delta', 'text' => $text],
            ]);
            $events[] = new RawEvent('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
            $index++;
        }

        foreach ($toolUses as $toolUse) {
            $name = (string)$toolUse['name'];
            $id = (string)$toolUse['id'];
            $input = $toolUse['input'] ?? [];
            $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = $json !== false ? $json : '{}';
            $midpoint = (int)ceil(strlen($json) / 2);
            $firstFragment = substr($json, 0, $midpoint);
            $secondFragment = substr($json, $midpoint);

            $events[] = new RawEvent('content_block_start', [
                'type' => 'content_block_start',
                'index' => $index,
                'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => []],
            ]);
            $events[] = new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $index,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => $firstFragment],
            ]);
            $events[] = new RawEvent('content_block_delta', [
                'type' => 'content_block_delta',
                'index' => $index,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => $secondFragment],
            ]);
            $events[] = new RawEvent('content_block_stop', ['type' => 'content_block_stop', 'index' => $index]);
            $index++;
        }

        $events[] = new RawEvent('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null],
            'usage' => ['output_tokens' => 50],
        ]);
        $events[] = new RawEvent('message_stop', ['type' => 'message_stop']);

        return $events;
    }

    public static function fromFixture(string $name): array
    {
        return Fixtures::load($name);
    }
}
