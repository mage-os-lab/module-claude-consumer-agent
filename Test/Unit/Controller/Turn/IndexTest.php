<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Controller\Turn;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Controller\Request\BodyReader;
use MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard;
use MageOS\AiShoppingAssistant\Controller\Result\EventStream;
use MageOS\AiShoppingAssistant\Controller\Result\EventStreamFactory;
use MageOS\AiShoppingAssistant\Controller\Result\JsonTurn;
use MageOS\AiShoppingAssistant\Controller\Result\JsonTurnFactory;
use MageOS\AiShoppingAssistant\Controller\Turn\Index;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Limits\BusyEvent;
use MageOS\AiShoppingAssistant\Model\Limits\Counters;
use MageOS\AiShoppingAssistant\Model\Limits\SlotLock;
use MageOS\AiShoppingAssistant\Model\Session\IdGenerator;
use MageOS\AiShoppingAssistant\Model\Session\Repository;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session as SessionResource;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class IndexTest extends TestCase
{
    private function requestWithBody(array $body): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $request->method('getHeader')->willReturn(false);
        return $request;
    }

    private function customerSession(?int $customerId = null): CustomerSession
    {
        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerId')->willReturn($customerId);
        return $session;
    }

    private function checkoutSession(int $quoteId = 5): CheckoutSession
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $session = $this->createMock(CheckoutSession::class);
        $session->method('getQuote')->willReturn($quote);
        return $session;
    }

    private function storeManager(int $storeId = 1): StoreManagerInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return $storeManager;
    }

    private function storeConfig(bool $enabled = true): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn($enabled);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function sessionRepositoryMintingFresh(): Repository
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn(null);
        return new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));
    }

    private function sessionRepositoryWithRow(string $sessionId, array $row): Repository
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn($row);
        return new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));
    }

    private function slotLock(bool $canAcquire): SlotLock
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn($canAcquire);
        $lockManager->method('unlock')->willReturn(true);
        return new SlotLock($lockManager, $this->storeConfig(), $this->createMock(LoggerInterface::class));
    }

    private function counters(bool $exceeded): Counters
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn($exceeded ? '999' : '0');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->method('unlock')->willReturn(true);
        return new Counters($cache, $lockManager, $this->createMock(LoggerInterface::class));
    }

    private function formKeyGuard(bool $valid): FormKeyGuard
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn($valid);
        $formKey = $this->createMock(FormKey::class);
        return new FormKeyGuard($validator, $formKey);
    }

    private function eventStreamResult(): EventStream
    {
        $result = $this->createMock(EventStream::class);
        $result->method('setTurn')->willReturnSelf();
        $result->method('setBusy')->willReturnSelf();
        return $result;
    }

    private function jsonTurnResult(): JsonTurn
    {
        $result = $this->createMock(JsonTurn::class);
        $result->method('setTurn')->willReturnSelf();
        $result->method('setBusy')->willReturnSelf();
        return $result;
    }

    private function eventStreamFactoryReturning(ResultInterface $result): EventStreamFactory
    {
        $factory = $this->createMock(EventStreamFactory::class);
        $factory->method('create')->willReturn($result);
        return $factory;
    }

    private function jsonTurnFactoryReturning(ResultInterface $result): JsonTurnFactory
    {
        $factory = $this->createMock(JsonTurnFactory::class);
        $factory->method('create')->willReturn($result);
        return $factory;
    }

    private function resultFactoryMock(): ResultFactory
    {
        $forward = $this->createMock(\Magento\Framework\Controller\Result\Forward::class);
        $forward->method('forward')->willReturnSelf();
        $factory = $this->createMock(ResultFactory::class);
        $factory->method('create')->willReturn($forward);
        return $factory;
    }

    private function jsonFactoryMock(): \Magento\Framework\Controller\Result\JsonFactory
    {
        $factory = $this->createMock(\Magento\Framework\Controller\Result\JsonFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            return $this->createMock(\Magento\Framework\Controller\Result\Json::class);
        });
        return $factory;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildIndex(array $overrides = []): Index
    {
        $defaults = [
            'bodyReader' => new BodyReader(),
            'formKeyGuard' => $this->formKeyGuard(true),
            'customerSession' => $this->customerSession(),
            'checkoutSession' => $this->checkoutSession(),
            'cartRepository' => $this->createMock(CartRepositoryInterface::class),
            'storeManager' => $this->storeManager(),
            'sessionRepository' => $this->sessionRepositoryMintingFresh(),
            'slotLock' => $this->slotLock(true),
            'counters' => $this->counters(false),
            'sessionManager' => $this->createMock(SessionManagerInterface::class),
            'storeConfig' => $this->storeConfig(),
            'eventStreamFactory' => $this->eventStreamFactoryReturning($this->eventStreamResult()),
            'jsonTurnFactory' => $this->jsonTurnFactoryReturning($this->jsonTurnResult()),
            'busyEvent' => new BusyEvent(),
            'remoteAddress' => $this->createMock(RemoteAddress::class),
            'request' => $this->requestWithBody(['message' => 'hello']),
            'response' => $this->createMock(HttpResponse::class),
            'jsonFactory' => $this->jsonFactoryMock(),
            'resultFactory' => $this->resultFactoryMock(),
        ];
        $merged = array_merge($defaults, $overrides);
        return new Index(...$merged);
    }

    public function testInvalidCsrfFailsValidation(): void
    {
        $index = $this->buildIndex(['formKeyGuard' => $this->formKeyGuard(false)]);
        $this->assertFalse($index->validateForCsrf($this->requestWithBody(['message' => 'hi'])));
    }

    public function testCreateCsrfValidationExceptionReturnsA403JsonResult(): void
    {
        $jsonResult = $this->createMock(\Magento\Framework\Controller\Result\Json::class);
        $jsonResult->expects($this->once())->method('setHttpResponseCode')->with(403);
        $jsonResult->expects($this->once())->method('setData')->with(['error' => 'form key']);
        $jsonFactory = $this->createMock(\Magento\Framework\Controller\Result\JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        $index = $this->buildIndex(['jsonFactory' => $jsonFactory]);
        $exception = $index->createCsrfValidationException($this->requestWithBody(['message' => 'hi']));

        $this->assertInstanceOf(\Magento\Framework\App\Request\InvalidRequestException::class, $exception);
    }

    public function testDisabledStoreForwardsToNoRoute(): void
    {
        $forward = $this->createMock(\Magento\Framework\Controller\Result\Forward::class);
        $forward->expects($this->once())->method('forward')->with('noroute')->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->expects($this->once())
            ->method('create')
            ->with(ResultFactory::TYPE_FORWARD)
            ->willReturn($forward);

        $index = $this->buildIndex([
            'storeConfig' => $this->storeConfig(false),
            'resultFactory' => $resultFactory,
        ]);

        $result = $index->execute();
        $this->assertSame($forward, $result);
    }

    public function testBusyResultWhenNoSlotIsAvailable(): void
    {
        $eventStreamResult = $this->eventStreamResult();
        $eventStreamResult->expects($this->once())
            ->method('setBusy')
            ->with($this->isInstanceOf(Event::class))
            ->willReturnSelf();
        $eventStreamResult->expects($this->never())->method('setTurn');

        $index = $this->buildIndex([
            'slotLock' => $this->slotLock(false),
            'eventStreamFactory' => $this->eventStreamFactoryReturning($eventStreamResult),
        ]);

        $result = $index->execute();
        $this->assertSame($eventStreamResult, $result);
    }

    public function testAFailureWhileBumpingTheCountersReleasesTheSlotBeforeItPropagates(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects($this->once())->method('unlock')->with('aiagent:turn:1:1');
        $slotLock = new SlotLock($lockManager, $this->storeConfig(), $this->createMock(LoggerInterface::class));

        $counterLockManager = $this->createMock(LockManagerInterface::class);
        $counterLockManager->method('lock')->willThrowException(new \RuntimeException('counter store down'));
        $counters = new Counters(
            $this->createMock(CacheInterface::class),
            $counterLockManager,
            $this->createMock(LoggerInterface::class)
        );

        $index = $this->buildIndex(['slotLock' => $slotLock, 'counters' => $counters]);

        $this->expectException(\RuntimeException::class);
        $index->execute();
    }

    public function testBusyResultWhenCountersThrowLimitExceeded(): void
    {
        $eventStreamResult = $this->eventStreamResult();
        $eventStreamResult->expects($this->once())
            ->method('setBusy')
            ->with($this->isInstanceOf(Event::class))
            ->willReturnSelf();
        $eventStreamResult->expects($this->never())->method('setTurn');

        $index = $this->buildIndex([
            'counters' => $this->counters(true),
            'eventStreamFactory' => $this->eventStreamFactoryReturning($eventStreamResult),
        ]);

        $result = $index->execute();
        $this->assertSame($eventStreamResult, $result);
    }

    public function testBusyResultWhenBindingRowIsAtTheSessionCapEmitsADistinctSessionCapEvent(): void
    {
        $sessionId = str_repeat('a', 64);
        $row = [
            'session_id' => $sessionId,
            'customer_id' => null,
            'quote_id' => 5,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 40,
        ];
        $capturedEvent = null;
        $eventStreamResult = $this->eventStreamResult();
        $eventStreamResult->expects($this->once())
            ->method('setBusy')
            ->with($this->callback(function (Event $event) use (&$capturedEvent): bool {
                $capturedEvent = $event;
                return true;
            }))
            ->willReturnSelf();
        $eventStreamResult->expects($this->never())->method('setTurn');

        $index = $this->buildIndex([
            'request' => $this->requestWithBody(['message' => 'hello', 'session' => $sessionId]),
            'sessionRepository' => $this->sessionRepositoryWithRow($sessionId, $row),
            'eventStreamFactory' => $this->eventStreamFactoryReturning($eventStreamResult),
        ]);

        $result = $index->execute();
        $this->assertSame($eventStreamResult, $result);
        $this->assertSame('session_cap', $capturedEvent->data['kind'] ?? null);
        $this->assertArrayNotHasKey('retry_after', $capturedEvent->data);
    }

    public function testWindowCounterIsAnchoredOnThePhpSessionWhenTheClientOmitsTheSessionId(): void
    {
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        $sessionManager->method('getSessionId')->willReturn('php-session-1');
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('0');
        $savedKeys = [];
        $cache->method('save')->willReturnCallback(
            static function (string $data, string $key) use (&$savedKeys): bool {
                $savedKeys[] = $key;
                return true;
            }
        );
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->method('unlock')->willReturn(true);
        $counters = new Counters($cache, $lockManager, $this->createMock(LoggerInterface::class));

        $index = $this->buildIndex([
            'sessionManager' => $sessionManager,
            'counters' => $counters,
            'request' => $this->requestWithBody(['message' => 'hello']),
        ]);
        $index->execute();

        $expectedPrefix = 'aiagent_cnt_s_' . substr(sha1('php-session-1'), 0, 24) . '_';
        $windowKeys = array_filter($savedKeys, static fn (string $key): bool => str_starts_with($key, $expectedPrefix));
        $this->assertCount(1, $windowKeys);
    }

    public function testWriteCloseIsCalledBeforeTheResultIsCreated(): void
    {
        $order = [];
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        $sessionManager->method('writeClose')->willReturnCallback(function () use (&$order): void {
            $order[] = 'writeClose';
        });

        $eventStreamResult = $this->eventStreamResult();
        $eventStreamFactory = $this->createMock(EventStreamFactory::class);
        $eventStreamFactory->method('create')->willReturnCallback(function () use (&$order, $eventStreamResult) {
            $order[] = 'create';
            return $eventStreamResult;
        });

        $index = $this->buildIndex([
            'sessionManager' => $sessionManager,
            'eventStreamFactory' => $eventStreamFactory,
        ]);
        $index->execute();

        $this->assertSame(['writeClose', 'create'], $order);
    }

    public function testStreamRequestWithStreamingEnabledUsesEventStreamFactory(): void
    {
        $eventStreamResult = $this->eventStreamResult();
        $eventStreamResult->expects($this->once())->method('setTurn')->willReturnSelf();
        $eventStreamFactory = $this->eventStreamFactoryReturning($eventStreamResult);

        $jsonTurnFactory = $this->createMock(JsonTurnFactory::class);
        $jsonTurnFactory->expects($this->never())->method('create');

        $index = $this->buildIndex([
            'request' => $this->requestWithBody(['message' => 'hello', 'stream' => 1]),
            'eventStreamFactory' => $eventStreamFactory,
            'jsonTurnFactory' => $jsonTurnFactory,
        ]);

        $index->execute();
    }

    public function testNonStreamRequestUsesJsonTurnFactory(): void
    {
        $eventStreamFactory = $this->createMock(EventStreamFactory::class);
        $eventStreamFactory->expects($this->never())->method('create');

        $jsonTurnResult = $this->jsonTurnResult();
        $jsonTurnResult->expects($this->once())->method('setTurn')->willReturnSelf();
        $jsonTurnFactory = $this->jsonTurnFactoryReturning($jsonTurnResult);

        $index = $this->buildIndex([
            'request' => $this->requestWithBody(['message' => 'hello', 'stream' => 0]),
            'eventStreamFactory' => $eventStreamFactory,
            'jsonTurnFactory' => $jsonTurnFactory,
        ]);

        $index->execute();
    }
}
