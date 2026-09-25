<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Exception;

final class PresentationRefused extends \RuntimeException
{
    private readonly ?string $gate;

    public function __construct(
        string $message,
        ?string $gate = null
    ) {
        parent::__construct($message);
        $this->gate = $gate;
    }

    public function getGate(): ?string
    {
        return $this->gate;
    }
}
