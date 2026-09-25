<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Event;

use MageOS\AiShoppingAssistant\Model\Agent\Event;

final class SseFrame
{
    public static function encode(Event $event): string
    {
        $json = json_encode($event->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json !== false ? $json : '{}';
        return "event: {$event->type}\ndata: {$json}\n\n";
    }
}
