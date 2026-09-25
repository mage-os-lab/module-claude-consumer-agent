<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

final class StreamedRoundFactory
{
    public function create(): StreamedRound
    {
        return new StreamedRound();
    }
}
