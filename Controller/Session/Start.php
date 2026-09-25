<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Session;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;

class Start implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const STARTER_LIMIT = 5;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard $formKeyGuard,
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\BodyReader $bodyReader,
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly \Magento\Checkout\Model\Session $checkoutSession,
        private readonly \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\Repository $sessionRepository,
        private readonly \MageOS\AiShoppingAssistant\Model\Surface\Resolver $surfaceResolver,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        private readonly \Magento\Framework\View\LayoutInterface $layout,
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
        try {
            $body = $this->bodyReader->readStart($this->request);
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
        $context = new SessionContext((string)($body->sessionId ?? ''), $customerId, $quoteId, $storeId, $page, $now);
        $surface = $this->surfaceResolver->resolve($storeId, $this->layout);
        $binding = $this->sessionRepository->bind($body->sessionId, $context, $surface);
        $this->sessionManager->writeClose();
        $agentConfig = $this->storeConfig->agent($storeId);
        $result = $this->jsonFactory->create();
        $result->setData([
            'session' => $binding->sessionId,
            'assistant_name' => $agentConfig->assistantName,
            'greeting' => $agentConfig->greeting,
            'starters' => array_slice($agentConfig->starters, 0, self::STARTER_LIMIT),
            'surface' => $surface,
            'streaming' => $agentConfig->streaming,
            'first_byte_threshold' => $agentConfig->firstByteThreshold,
            'show_ai_label' => $agentConfig->showAiLabel,
            'contact' => ['url' => $agentConfig->contactUrl, 'label' => $agentConfig->contactLabel],
            'fresh' => $binding->isNew,
        ]);
        $this->response->setNoCacheHeaders();
        $this->response->setMetadata('NotCacheable', true);
        return $result;
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
