<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

use GuzzleHttp\Exception\ConnectException;
use MageOS\AiShoppingAssistant\Api\Client\MessagesClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The Messages response arrives chunked, so PHP attaches its dechunk read filter. On a filtered
 * stream a read timeout surfaces as fread() returning false rather than an empty string, which
 * is why the reader below separates a timed-out read from a genuine read failure.
 */
final class GuzzleMessagesClient implements MessagesClientInterface
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2023-06-01';
    public const MAX_RETRIES = 2;
    private const READ_CHUNK_BYTES = 256;
    private const TIMEOUT_MARGIN_SECONDS = 5;
    private const HEARTBEAT_SLICE_SECONDS = 1;
    private const MAX_ERROR_BYTES = 65_536;

    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 529];
    private const MAX_RETRY_AFTER_SECONDS = 30.0;

    public function __construct(
        private readonly \GuzzleHttp\ClientInterface $http,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $config,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Model\Client\Sleeper $sleeper
    ) {
    }

    public function stream(array $request, ?callable $onWaiting = null, ?int $storeId = null): \Generator
    {
        $storeId ??= 0;
        $rawApiKey = $this->config->apiKey($storeId);
        if ($rawApiKey === '') {
            throw new Exception\Unauthorized('No API key configured for store ' . $storeId);
        }
        if (preg_match('/^[\x21-\x7e]+$/', $rawApiKey) !== 1) {
            throw new Exception\Unauthorized('The configured API key is not a valid header value.');
        }
        $apiKey = new ApiKey($rawApiKey);
        $agentConfig = $this->config->agent($storeId);
        $body = $request;
        $body['stream'] = true;
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedBody = $encodedBody !== false ? $encodedBody : '{}';
        if ($agentConfig->debugLog) {
            $this->logger->debug('aiagent request body', ['body' => $encodedBody]);
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new Exception\Transport(
                'The model call streams over the PHP stream handler, which needs allow_url_fopen enabled in php.ini.'
            );
        }
        $response = $this->send($encodedBody, $agentConfig->connectTimeout, $agentConfig->requestTimeout, $apiKey);

        $lineReader = new SseLineReader();
        $lastEventType = null;
        $chunks = $this->readChunks($response->getBody(), $onWaiting, $agentConfig->requestTimeout);
        foreach ($lineReader->read($chunks) as $rawEvent) {
            if ($agentConfig->debugLog) {
                $this->logger->debug('aiagent frame', ['type' => $rawEvent->type, 'data' => $rawEvent->data]);
            }
            $lastEventType = $rawEvent->type;
            yield $rawEvent;
        }
        if ($lastEventType !== 'message_stop') {
            throw new Exception\Transport('The model stream ended before message_stop.');
        }
    }

    private function send(
        string $encodedBody,
        int $connectTimeout,
        int $requestTimeout,
        string|ApiKey $apiKey
    ): ResponseInterface {
        $key = $apiKey instanceof ApiKey ? $apiKey : new ApiKey($apiKey);
        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'x-api-key' => (string)$key,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                        'accept' => 'text/event-stream',
                    ],
                    'body' => $encodedBody,
                    'stream' => true,
                    'connect_timeout' => $connectTimeout,
                    'read_timeout' => $requestTimeout,
                    'timeout' => $requestTimeout + self::TIMEOUT_MARGIN_SECONDS,
                    'http_errors' => false,
                ]);
            } catch (ConnectException $exception) {
                if ($attempt < self::MAX_RETRIES) {
                    $this->sleeper->sleep($this->backoff($attempt));
                    $attempt++;
                    continue;
                }
                throw new Exception\Transport($exception->getMessage(), null, null, $exception);
            }

            $status = $response->getStatusCode();
            if ($status === 200) {
                return $response;
            }

            $errorBody = json_decode($response->getBody()->read(self::MAX_ERROR_BYTES), true);
            $errorBody = is_array($errorBody) ? $errorBody : [];

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < self::MAX_RETRIES) {
                $wait = $this->retryAfterFromHeader($response) ?? $this->backoff($attempt);
                $this->sleeper->sleep(min($wait, self::MAX_RETRY_AFTER_SECONDS));
                $attempt++;
                continue;
            }

            throw $this->mapStatus($status, $errorBody, $response);
        }
    }

    private function readChunks(StreamInterface $stream, ?callable $onWaiting, int $requestTimeout): \Generator
    {
        $resource = $stream->detach();
        if (!is_resource($resource)) {
            while (!$stream->eof()) {
                if ($onWaiting !== null) {
                    $onWaiting();
                }
                $chunk = $stream->read(self::READ_CHUNK_BYTES);
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
            return;
        }

        try {
            yield from $this->readChunksFromResource($resource, $onWaiting, $requestTimeout);
        } finally {
            fclose($resource);
        }
    }

    private function readChunksFromResource($resource, ?callable $onWaiting, int $requestTimeout): \Generator
    {
        stream_set_timeout($resource, self::HEARTBEAT_SLICE_SECONDS);
        $lastData = time();
        while (!feof($resource)) {
            if ($onWaiting !== null) {
                $onWaiting();
            }
            $chunk = fread($resource, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                if (!$this->readTimedOut($resource)) {
                    throw new Exception\Transport('The model stream could not be read to the end.');
                }
                $chunk = '';
            }
            if ($chunk !== '') {
                $lastData = time();
                yield $chunk;
                continue;
            }
            if (time() - $lastData >= $requestTimeout) {
                throw new Exception\Transport('The model stream went silent for ' . $requestTimeout . ' seconds.');
            }
        }
    }

    private function readTimedOut($resource): bool
    {
        $meta = stream_get_meta_data($resource);
        return (bool)($meta['timed_out'] ?? false);
    }

    private function retryAfterFromHeader(ResponseInterface $response): ?float
    {
        $values = $response->getHeader('retry-after');
        if ($values === [] || !is_numeric($values[0])) {
            return null;
        }
        return (float)$values[0];
    }

    private function backoff(int $attempt): float
    {
        return 0.5 * (2 ** $attempt);
    }

    private function mapStatus(int $status, array $errorBody, ResponseInterface $response): Exception\ApiException
    {
        $message = $errorBody['error']['message'] ?? ('HTTP ' . $status);
        $apiType = $errorBody['error']['type'] ?? null;
        if ($status === 400) {
            return new Exception\BadRequest($message, $status, $apiType);
        }
        if ($status === 401) {
            return new Exception\Unauthorized($message, $status, $apiType);
        }
        if ($status === 404) {
            return new Exception\NotFound($message, $status, $apiType);
        }
        if ($status === 429) {
            $retryAfter = $this->retryAfterFromHeader($response);
            return new Exception\RateLimited($message, $retryAfter !== null ? (int)$retryAfter : 0, $status, $apiType);
        }
        if ($status >= 500) {
            return new Exception\ServerError($message, $status, $apiType);
        }
        return new Exception\ApiException($message, $status, $apiType);
    }
}
