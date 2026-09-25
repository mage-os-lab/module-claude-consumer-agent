<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Model\Client\ApiKey;
use MageOS\AiShoppingAssistant\Model\Client\Exception\BadRequest;
use MageOS\AiShoppingAssistant\Model\Client\Exception\ServerError;
use MageOS\AiShoppingAssistant\Model\Client\Exception\Transport;
use MageOS\AiShoppingAssistant\Model\Client\Exception\Unauthorized;
use MageOS\AiShoppingAssistant\Model\Client\GuzzleMessagesClient;
use MageOS\AiShoppingAssistant\Model\Client\Sleeper;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class GuzzleMessagesClientTest extends TestCase
{
    private const SSE_TEXT = "event: message_start\n"
        . "data: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":10,\"output_tokens\":1,"
        . "\"cache_read_input_tokens\":0,\"cache_creation_input_tokens\":0}}}\n\n"
        . "event: content_block_start\n"
        . "data: {\"type\":\"content_block_start\",\"index\":0,\"content_block\":{\"type\":\"text\",\"text\":\"\"}}\n\n"
        . "event: content_block_delta\n"
        . "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"hi\"}}\n\n"
        . "event: content_block_stop\n"
        . "data: {\"type\":\"content_block_stop\",\"index\":0}\n\n"
        . "event: message_delta\n"
        . "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n"
        . "event: message_stop\n"
        . "data: {\"type\":\"message_stop\"}\n\n";

    private function buildStoreConfig(?string $apiKey = 'sk-ant-test'): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($apiKey) {
                if ($path === 'ai_integration/aiagent/model/api_key') {
                    return $apiKey;
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

    private function buildClientWithHandler(MockHandler $mockHandler, array &$history): Client
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));
        return new Client(['handler' => $handlerStack]);
    }

    private function errorBody(string $type, string $message): string
    {
        $json = json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]);
        return $json !== false ? $json : '{}';
    }

    public function testStreamYieldsEventsFromA200Response(): void
    {
        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], self::SSE_TEXT)]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(1, $history);
        $types = array_map(static fn ($event) => $event->type, $events);
        $this->assertSame(
            [
                'message_start',
                'content_block_start',
                'content_block_delta',
                'content_block_stop',
                'message_delta',
                'message_stop',
            ],
            $types
        );
        $this->assertSame('hi', $events[2]->data['delta']['text']);
    }

    public function testStreamCutShortBeforeMessageStopThrowsTransport(): void
    {
        $truncated = "event: message_start\n"
            . "data: {\"type\":\"message_start\",\"message\":{\"usage\":{}}}\n\n"
            . "event: content_block_delta\n"
            . "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"hi\"}}\n\n";
        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], $truncated)]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $events = [];
        try {
            foreach ($client->stream(['messages' => []]) as $event) {
                $events[] = $event;
            }
            $this->fail('Expected Transport was not thrown');
        } catch (Transport $exception) {
            $this->assertSame('The model stream ended before message_stop.', $exception->getMessage());
        }
        $this->assertCount(2, $events);
        $this->assertSame('content_block_delta', $events[1]->type);
    }

    public function testRequestCarriesAReadTimeoutAndATotalTimeoutAboveIt(): void
    {
        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], self::SSE_TEXT)]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        iterator_to_array($client->stream(['messages' => []]), false);

        $options = $history[0]['options'];
        $this->assertSame(10, $options['connect_timeout']);
        $this->assertSame(120, $options['read_timeout']);
        $this->assertSame(125, $options['timeout']);
        $this->assertTrue($options['stream']);
    }

    public function testRetryAfterOn429ThenSucceeds(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(429, ['retry-after' => '2'], $this->errorBody('rate_limit_error', 'Slow down')),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([2.0], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testRetryAfterAsAnHttpDateFallsBackToBackoff(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(
                429,
                ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
                $this->errorBody('rate_limit_error', 'Slow down')
            ),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([0.5], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function test400MapsToBadRequestWithApiMessage(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(400, [], $this->errorBody('invalid_request_error', 'model is required')),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected BadRequest was not thrown');
        } catch (BadRequest $exception) {
            $this->assertSame('model is required', $exception->getMessage());
            $this->assertSame(400, $exception->getStatus());
        }
        $this->assertCount(1, $history);
    }

    public function test500TwiceThenSucceeds(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(3, $history);
        $this->assertSame([0.5, 1.0], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function test500ThreeTimesThrowsServerError(): void
    {
        $history = [];
        $mockHandler = new MockHandler([
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
            new Response(500, [], $this->errorBody('api_error', 'Server error')),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $this->expectException(ServerError::class);
        try {
            iterator_to_array($client->stream(['messages' => []]), false);
        } finally {
            $this->assertCount(3, $history);
            $this->assertSame([0.5, 1.0], $sleeps);
        }
    }

    public function testOversizedErrorBodyIsReadOnlyUpToTheCapAndFallsBackToTheStatus(): void
    {
        $hugeMessage = str_repeat('x', 100_000);
        $hugeErrorBody = '{"type":"error","error":{"type":"api_error","message":"' . $hugeMessage . '"}}';
        $history = [];
        $mockHandler = new MockHandler([
            new Response(500, [], $hugeErrorBody),
            new Response(500, [], $hugeErrorBody),
            new Response(500, [], $hugeErrorBody),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected ServerError was not thrown');
        } catch (ServerError $exception) {
            $this->assertSame('HTTP 500', $exception->getMessage());
        }
    }

    public function testConnectExceptionIsRetried(): void
    {
        $history = [];
        $request = new Request('POST', GuzzleMessagesClient::ENDPOINT);
        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', $request),
            new Response(200, [], self::SSE_TEXT),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeps = [];
        $sleeper->method('sleep')->willReturnCallback(static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);
        $events = iterator_to_array($client->stream(['messages' => []]), false);

        $this->assertCount(2, $history);
        $this->assertSame([0.5], $sleeps);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testApiKeyArgumentInTheSendFrameIsWrappedAndMasked(): void
    {
        $secretKey = 'sk-ant-do-not-leak-me';
        $history = [];
        $request = new Request('POST', GuzzleMessagesClient::ENDPOINT);
        $mockHandler = new MockHandler([
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
            new ConnectException('Connection refused', $request),
        ]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig($secretKey), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected a Transport exception was not thrown');
        } catch (Transport $exception) {
            $sendFrame = null;
            foreach ($exception->getTrace() as $frame) {
                if (($frame['function'] ?? null) === 'send') {
                    $sendFrame = $frame;
                    break;
                }
            }
            $this->assertNotNull($sendFrame, 'Expected a send() frame in the exception trace');
            $apiKeyArgument = $sendFrame['args'][3] ?? null;
            $this->assertInstanceOf(ApiKey::class, $apiKeyArgument);
            $this->assertStringNotContainsString($secretKey, print_r($apiKeyArgument, true));
        }
    }

    public function testNoRetryAfterFirstBodyByte(): void
    {
        $callCount = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturnCallback(static function () use (&$callCount): string {
            $callCount++;
            if ($callCount === 1) {
                return "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{}}}\n\n";
            }
            throw new \RuntimeException('connection reset');
        });

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->once())->method('request')->willReturn($response);

        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $events = [];
        try {
            foreach ($client->stream(['messages' => []]) as $event) {
                $events[] = $event;
            }
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('connection reset', $exception->getMessage());
        }
        $this->assertCount(1, $events);
        $this->assertSame('message_start', $events[0]->type);
    }

    public function testOnWaitingFiresEveryIterationEvenWhenChunksCarryData(): void
    {
        $chunks = [
            "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{}}}\n\n",
            "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n",
        ];
        $callCount = 0;
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('detach')->willReturn(null);
        $stream->method('eof')->willReturnCallback(
            static function () use (&$callCount, $chunks): bool {
                return $callCount >= count($chunks);
            }
        );
        $stream->method('read')->willReturnCallback(
            static function () use (&$callCount, $chunks): string {
                return $chunks[$callCount++];
            }
        );

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);

        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->once())->method('request')->willReturn($response);

        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(), $logger, $sleeper);

        $waits = 0;
        $events = iterator_to_array(
            $client->stream(['messages' => []], static function () use (&$waits): void {
                $waits++;
            }),
            false
        );

        $this->assertSame(2, $waits);
        $this->assertSame('message_stop', $events[count($events) - 1]->type);
    }

    public function testMissingApiKeyThrowsUnauthorizedWithoutARequest(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->never())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig(null), $logger, $sleeper);

        $this->expectException(Unauthorized::class);
        iterator_to_array($client->stream(['messages' => []]), false);
    }

    public function testInvalidApiKeyCharactersThrowUnauthorizedWithoutARequestOrTheKeyInTheMessage(): void
    {
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->expects($this->never())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $sleeper = $this->createMock(Sleeper::class);
        $sleeper->expects($this->never())->method('sleep');

        $client = new GuzzleMessagesClient($http, $this->buildStoreConfig("sk-ant-bad\nkey"), $logger, $sleeper);

        try {
            iterator_to_array($client->stream(['messages' => []]), false);
            $this->fail('Expected Unauthorized was not thrown');
        } catch (Unauthorized $exception) {
            $this->assertSame('The configured API key is not a valid header value.', $exception->getMessage());
        }
    }

    public function testATimedOutReadOnAChunkedStreamKeepsWaitingInsteadOfEndingTheStream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$peer, $reader] = $pair;
        stream_filter_append($reader, 'dechunk', STREAM_FILTER_READ);
        $this->writeChunk($peer, "event: message_start\ndata: {\"type\":\"message_start\"}\n\n");

        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], \GuzzleHttp\Psr7\Utils::streamFor($reader))]);
        $http = $this->buildClientWithHandler($mockHandler, $history);
        $sleeper = $this->createMock(Sleeper::class);
        $client = new GuzzleMessagesClient(
            $http,
            $this->buildStoreConfig(),
            $this->createMock(LoggerInterface::class),
            $sleeper
        );

        $waits = 0;
        $onWaiting = function () use (&$waits, $peer): void {
            $waits++;
            if ($waits !== 3) {
                return;
            }
            $this->writeChunk($peer, "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n");
            fwrite($peer, "0\r\n\r\n");
            fclose($peer);
        };

        $events = iterator_to_array($client->stream(['messages' => []], $onWaiting), false);

        $types = array_map(static fn ($event) => $event->type, $events);
        $this->assertSame(['message_start', 'message_stop'], $types);
    }

    public function testAReadFailureThatIsNotATimeoutThrowsTransport(): void
    {
        $wrapper = 'aiagentreadfail';
        if (in_array($wrapper, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($wrapper);
        }
        stream_wrapper_register($wrapper, ReadFailStreamWrapper::class);

        try {
            $resource = fopen($wrapper . '://stream', 'r');
            $this->assertIsResource($resource);
            $history = [];
            $mockHandler = new MockHandler([new Response(200, [], \GuzzleHttp\Psr7\Utils::streamFor($resource))]);
            $http = $this->buildClientWithHandler($mockHandler, $history);
            $client = new GuzzleMessagesClient(
                $http,
                $this->buildStoreConfig(),
                $this->createMock(LoggerInterface::class),
                $this->createMock(Sleeper::class)
            );

            $this->expectException(Transport::class);
            $this->expectExceptionMessage('The model stream could not be read to the end.');
            iterator_to_array($client->stream(['messages' => []]), false);
        } finally {
            stream_wrapper_unregister($wrapper);
        }
    }

    private function writeChunk($resource, string $payload): void
    {
        fwrite($resource, dechex(strlen($payload)) . "\r\n" . $payload . "\r\n");
    }
}
