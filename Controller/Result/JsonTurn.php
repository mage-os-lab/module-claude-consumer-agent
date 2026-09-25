<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Result;

use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\Controller\AbstractResult;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Limits\SlotHandle;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

/**
 * Per-request state arrives through setTurn()/setBusy() rather than the constructor, because
 * Magento's compiled factory drops null constructor arguments.
 */
class JsonTurn extends AbstractResult
{
    private ?Binding $binding = null;

    private ?string $message = null;

    private ?SessionContext $context = null;

    private ?SlotHandle $slot = null;

    private ?Event $busyEvent = null;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator $orchestrator,
        private readonly \Psr\Log\LoggerInterface $logger
    ) {
    }

    public function setTurn(Binding $binding, string $message, SessionContext $context, ?SlotHandle $slot): self
    {
        $this->binding = $binding;
        $this->message = $message;
        $this->context = $context;
        $this->slot = $slot;
        return $this;
    }

    public function setBusy(Event $busyEvent): self
    {
        $this->busyEvent = $busyEvent;
        return $this;
    }

    protected function render(HttpResponseInterface $response)
    {
        $this->registerSlotReleaseFallback();
        try {
            if ($this->context === null && $this->busyEvent === null) {
                throw new \LogicException('JsonTurn requires setTurn() or setBusy() to be called before render().');
            }
            $events = $this->busyEvent !== null ? $this->busyEvents() : $this->collectEvents();
            $response->setNoCacheHeaders();
            $response->setMetadata('NotCacheable', true);
            $body = json_encode(
                ['events' => array_map(static fn (Event $event): array => $event->toArray(), $events)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $response->representJson($body !== false ? $body : '{"events":[]}');
            return $this;
        } finally {
            $this->slot?->release();
        }
    }

    private function registerSlotReleaseFallback(): void
    {
        $slot = $this->slot;
        if ($slot === null) {
            return;
        }
        register_shutdown_function(static function () use ($slot): void {
            $slot->release();
        });
    }

    private function busyEvents(): array
    {
        return [$this->busyEvent, Event::turnComplete('busy', [], 0, 0)];
    }

    private function collectEvents(): array
    {
        $events = [];
        try {
            foreach ($this->orchestrator->streamTurn($this->binding, $this->message, $this->context) as $event) {
                $events[] = $event;
            }
        } catch (\Throwable $exception) {
            $this->logError($exception);
            $events[] = Event::error('Something went wrong. Please try again.');
        } finally {
            $this->slot?->release();
        }
        return $events;
    }

    private function logError(\Throwable $exception): void
    {
        $masked = preg_replace('/sk-ant-[A-Za-z0-9_-]+/', 'sk-ant-***', $exception->getMessage());
        $message = $masked !== null ? $masked : $exception->getMessage();
        $this->logger->error(get_class($exception) . ': ' . $message, ['exception' => $exception]);
    }
}
