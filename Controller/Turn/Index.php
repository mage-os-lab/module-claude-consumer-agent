<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Turn;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use MageOS\AiShoppingAssistant\Controller\Request\TurnRequest;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Config\Source\Streaming;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use MageOS\AiShoppingAssistant\Model\Limits\Exception\LimitExceeded;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const SLOT_RETRY_AFTER = 5;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\BodyReader $bodyReader,
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard $formKeyGuard,
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly \Magento\Checkout\Model\Session $checkoutSession,
        private readonly \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\Repository $sessionRepository,
        private readonly \MageOS\AiShoppingAssistant\Model\Limits\SlotLock $slotLock,
        private readonly \MageOS\AiShoppingAssistant\Model\Limits\Counters $counters,
        private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \MageOS\AiShoppingAssistant\Controller\Result\EventStreamFactory $eventStreamFactory,
        private readonly \MageOS\AiShoppingAssistant\Controller\Result\JsonTurnFactory $jsonTurnFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Limits\BusyEvent $busyEvent,
        private readonly \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        private readonly \Magento\Framework\App\RequestInterface $request,
        private readonly \Magento\Framework\App\Response\Http $response,
        private readonly \Magento\Framework\Controller\Result\JsonFactory $jsonFactory,
        private readonly \Magento\Framework\Controller\ResultFactory $resultFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $storeId = (int)$this->storeManager->getStore()->getId();
        if (!$this->storeConfig->isEnabled($storeId)) {
            return $this->noRoute();
        }
        $agentConfig = $this->storeConfig->agent($storeId);
        try {
            $body = $this->bodyReader->read($this->request, $agentConfig);
        } catch (\InvalidArgumentException $exception) {
            return $this->badRequest();
        }
        $customerId = $this->customerSession->getCustomerId();
        $customerId = $customerId === null ? null : (int)$customerId;
        $quote = $this->checkoutSession->getQuote();
        if ($quote->getId() === null) {
            $this->cartRepository->save($quote);
            $this->checkoutSession->setQuoteId((int)$quote->getId());
        }
        $quoteId = (int)$quote->getId();
        $page = PageContext::fromArray($body->page);
        $now = new \DateTimeImmutable('now');
        $preContext = new SessionContext((string)($body->sessionId ?? ''), $customerId, $quoteId, $storeId, $page, $now);
        $binding = $this->sessionRepository->bind($body->sessionId, $preContext);
        $context = new SessionContext($binding->sessionId, $customerId, $quoteId, $storeId, $page, $now);
        $ip = (string)($this->remoteAddress->getRemoteAddress() ?: '');
        $browserSessionId = (string)$this->sessionManager->getSessionId();
        $slot = $this->slotLock->acquire($storeId);
        try {
            $retryAfter = $this->bumpCounters($binding->sessionId, $browserSessionId, $ip, $agentConfig);
            $turnsSoFar = $binding->row !== null ? (int)($binding->row['turns'] ?? 0) : 0;
            $overSessionCap = $turnsSoFar >= $agentConfig->turnsPerSession;
            $this->sessionManager->writeClose();
        } catch (\Throwable $exception) {
            $slot?->release();
            throw $exception;
        }
        if ($slot === null || $retryAfter !== null || $overSessionCap) {
            $slot?->release();
            if ($overSessionCap) {
                return $this->busyResult($body, $this->busyEvent->sessionCap());
            }
            $busyRetryAfter = $retryAfter ?? self::SLOT_RETRY_AFTER;
            return $this->busyResult($body, $this->busyEvent->event($busyRetryAfter));
        }
        if ($body->wantsStream && $agentConfig->streaming !== Streaming::OFF) {
            return $this->eventStreamFactory->create()->setTurn($binding, $body->message, $context, $slot);
        }
        return $this->jsonTurnFactory->create()->setTurn($binding, $body->message, $context, $slot);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->formKeyGuard->isValid($request);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData(['error' => 'form key']);
        $this->response->setNoCacheHeaders();
        $this->response->setMetadata('NotCacheable', true);
        return new InvalidRequestException($result);
    }

    private function bumpCounters(
        string $sessionId,
        string $browserSessionId,
        string $ip,
        AgentConfig $agentConfig
    ): ?int {
        try {
            $this->counters->bump($sessionId, $browserSessionId, $ip, $agentConfig);
        } catch (LimitExceeded $exception) {
            return $exception->getRetryAfter();
        }
        return null;
    }

    private function busyResult(TurnRequest $body, Event $event): ResultInterface
    {
        if ($body->wantsStream) {
            return $this->eventStreamFactory->create()->setBusy($event);
        }
        return $this->jsonTurnFactory->create()->setBusy($event);
    }

    private function badRequest(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(400);
        $result->setData(['error' => 'bad request']);
        $this->response->setNoCacheHeaders();
        $this->response->setMetadata('NotCacheable', true);
        return $result;
    }

    private function noRoute(): ResultInterface
    {
        $forward = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD);
        $forward->forward('noroute');
        return $forward;
    }
}
