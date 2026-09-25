<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Limits;

use MageOS\AiShoppingAssistant\Model\Agent\Event;

final class BusyEvent
{
    public function event(int $retryAfter): Event
    {
        return Event::error(
            'The assistant is busy right now. Please try again in a few seconds.',
            $retryAfter
        );
    }

    public function sessionCap(): Event
    {
        return Event::error(
            'This conversation has reached its length limit. Start a new conversation to keep going.',
            null,
            'session_cap'
        );
    }
}
