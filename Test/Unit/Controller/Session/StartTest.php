<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Controller\Session;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\View\LayoutInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Controller\Request\BodyReader;
use MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard;
use MageOS\AiShoppingAssistant\Controller\Session\Start;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Session\IdGenerator;
use MageOS\AiShoppingAssistant\Model\Session\Repository;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session as SessionResource;
use MageOS\AiShoppingAssistant\Model\Surface\Resolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class StartTest extends TestCase
{
    private function requestWithBody(array $body): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $request->method('getHeader')->willReturn(false);
        return $request;
    }

    private function requestWithRawContent(string $raw): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn($raw);
        $request->method('getHeader')->willReturn(false);
        return $request;
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

    private function resolver(StoreConfig $storeConfig): Resolver
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        return new Resolver($storeConfig, $scopeConfig);
    }

    private function sessionRepositoryMintingFresh(): Repository
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn(null);
        return new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));
    }

    private function sessionRepositoryWithRow(array $row): Repository
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn($row);
        return new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));
    }

    private function formKeyGuard(bool $valid): FormKeyGuard
    {
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn($valid);
        $formKey = $this->createMock(FormKey::class);
        return new FormKeyGuard($validator, $formKey);
    }

    private function jsonFactoryMock(): JsonFactory
    {
        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            return $this->createMock(JsonResult::class);
        });
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

    private function buildStart(array $overrides = []): Start
    {
        $storeConfig = $overrides['storeConfig'] ?? $this->storeConfig();
        $defaults = [
            'formKeyGuard' => $this->formKeyGuard(true),
            'bodyReader' => new BodyReader(),
            'customerSession' => $this->createMock(CustomerSession::class),
            'checkoutSession' => $this->checkoutSession(),
            'cartRepository' => $this->createMock(CartRepositoryInterface::class),
            'storeManager' => $this->storeManager(),
            'sessionRepository' => $this->sessionRepositoryMintingFresh(),
            'surfaceResolver' => $this->resolver($storeConfig),
            'storeConfig' => $storeConfig,
            'sessionManager' => $this->createMock(\Magento\Framework\Session\SessionManagerInterface::class),
            'layout' => $this->createMock(LayoutInterface::class),
            'request' => $this->requestWithBody([]),
            'response' => $this->createMock(HttpResponse::class),
            'jsonFactory' => $this->jsonFactoryMock(),
            'resultFactory' => $this->resultFactoryMock(),
        ];
        $merged = array_merge($defaults, $overrides);
        return new Start(...$merged);
    }

    public function testSnapshotContainsSessionIdAndVoiceFields(): void
    {
        $captured = null;
        $jsonResult = $this->createMock(JsonResult::class);
        $jsonResult->method('setData')->willReturnCallback(function (array $data) use ($jsonResult, &$captured) {
            $captured = $data;
            return $jsonResult;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        $start = $this->buildStart(['jsonFactory' => $jsonFactory]);
        $result = $start->execute();

        $this->assertSame($jsonResult, $result);
        $this->assertArrayHasKey('session', $captured);
        $this->assertSame(64, strlen($captured['session']));
        $this->assertSame('the shopping assistant', $captured['assistant_name']);
        $this->assertCount(5, $captured['starters']);
        $this->assertSame('overlay', $captured['surface']);
        $this->assertTrue($captured['fresh']);
    }

    public function testMintsAFreshSessionWithTheResolvedSurfaceWhenNoSessionIdIsGiven(): void
    {
        $start = $this->buildStart(['request' => $this->requestWithBody(['session' => null])]);
        $result = $start->execute();
        $this->assertInstanceOf(JsonResult::class, $result);
    }

    public function testMintsThroughBindWithTheResolvedSurfaceWhenNoSessionIdIsGiven(): void
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn(null);
        $captured = null;
        $resource->method('insert')->willReturnCallback(function (array $row) use (&$captured): void {
            $captured = $row;
        });
        $sessionRepository = new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));

        $start = $this->buildStart([
            'request' => $this->requestWithBody(['session' => null]),
            'sessionRepository' => $sessionRepository,
        ]);
        $start->execute();

        $this->assertSame('overlay', $captured['surface']);
    }

    public function testBindsAnExistingSessionWhenAValidSessionIdIsGiven(): void
    {
        $sessionId = str_repeat('b', 64);
        $row = [
            'session_id' => $sessionId,
            'customer_id' => null,
            'quote_id' => 5,
            'store_id' => 1,
            'surface' => 'side_cart',
            'state' => '{}',
            'version' => 0,
            'turns' => 3,
        ];
        $captured = null;
        $jsonResult = $this->createMock(JsonResult::class);
        $jsonResult->method('setData')->willReturnCallback(function (array $data) use ($jsonResult, &$captured) {
            $captured = $data;
            return $jsonResult;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        $start = $this->buildStart([
            'request' => $this->requestWithBody(['session' => $sessionId]),
            'sessionRepository' => $this->sessionRepositoryWithRow($row),
            'jsonFactory' => $jsonFactory,
        ]);
        $start->execute();

        $this->assertSame($sessionId, $captured['session']);
        $this->assertFalse($captured['fresh']);
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

        $start = $this->buildStart([
            'storeConfig' => $this->storeConfig(false),
            'resultFactory' => $resultFactory,
        ]);
        $result = $start->execute();

        $this->assertSame($forward, $result);
    }

    public function testInvalidCsrfFailsValidation(): void
    {
        $start = $this->buildStart(['formKeyGuard' => $this->formKeyGuard(false)]);
        $this->assertFalse($start->validateForCsrf($this->requestWithBody([])));
    }

    public function testCreateCsrfValidationExceptionReturnsA403JsonResult(): void
    {
        $jsonResult = $this->createMock(JsonResult::class);
        $jsonResult->expects($this->once())->method('setHttpResponseCode')->with(403);
        $jsonResult->expects($this->once())->method('setData')->with(['error' => 'form key']);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        $start = $this->buildStart(['jsonFactory' => $jsonFactory]);
        $exception = $start->createCsrfValidationException($this->requestWithBody([]));

        $this->assertInstanceOf(\Magento\Framework\App\Request\InvalidRequestException::class, $exception);
    }

    public function testBadRequestOnInvalidJsonBody(): void
    {
        $jsonResult = $this->createMock(JsonResult::class);
        $jsonResult->expects($this->once())->method('setHttpResponseCode')->with(400);
        $jsonResult->expects($this->once())->method('setData')->with(['error' => 'bad request']);
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($jsonResult);

        $badRequest = $this->buildStart([
            'jsonFactory' => $jsonFactory,
            'request' => $this->requestWithRawContent('not json'),
        ]);
        $result = $badRequest->execute();

        $this->assertSame($jsonResult, $result);
    }

    public function testNormalizesANumericStringCustomerIdBeforeBindingTheSession(): void
    {
        $resource = $this->createMock(SessionResource::class);
        $resource->method('load')->willReturn(null);
        $captured = null;
        $resource->method('insert')->willReturnCallback(function (array $row) use (&$captured): void {
            $captured = $row;
        });
        $sessionRepository = new Repository($resource, new IdGenerator(), $this->createMock(LoggerInterface::class));
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('getCustomerId')->willReturn('7');

        $start = $this->buildStart([
            'request' => $this->requestWithBody(['session' => null]),
            'sessionRepository' => $sessionRepository,
            'customerSession' => $customerSession,
        ]);
        $start->execute();

        $this->assertSame(7, $captured['customer_id']);
    }
}
