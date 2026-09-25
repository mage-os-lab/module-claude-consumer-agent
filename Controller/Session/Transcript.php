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

class Transcript implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\FormKeyGuard $formKeyGuard,
        private readonly \MageOS\AiShoppingAssistant\Controller\Request\BodyReader $bodyReader,
        private readonly \Magento\Customer\Model\Session $customerSession,
        private readonly \Magento\Checkout\Model\Session $checkoutSession,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\Repository $sessionRepository,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository $transcriptRepository,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\TranscriptView $transcriptView,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry $presentationRegistry,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Magento\Framework\Session\SessionManagerInterface $sessionManager,
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
        $quoteId = (int)$this->checkoutSession->getQuote()->getId();
        $page = PageContext::fromArray($body->page);
        $now = new \DateTimeImmutable('now');
        $context = new SessionContext((string)($body->sessionId ?? ''), $customerId, $quoteId, $storeId, $page, $now);
        $binding = $this->sessionRepository->find($body->sessionId, $context);
        $this->sessionManager->writeClose();
        $result = $this->jsonFactory->create();
        if ($binding === null) {
            $result->setData(['session' => null, 'messages' => []]);
        } else {
            $rows = $this->transcriptRepository->load($binding->sessionId);
            $messages = $this->transcriptView->render($rows, $binding->state, $this->presentationRegistry);
            $result->setData(['session' => $binding->sessionId, 'messages' => $messages]);
        }
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
