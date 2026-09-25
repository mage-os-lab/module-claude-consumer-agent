<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

final class Fixtures
{
    public static function path(string $name): string
    {
        return __DIR__ . '/../../Test/Fixtures/sse/' . $name . '.sse';
    }

    public static function load(string $name): array
    {
        $content = file_get_contents(self::path($name));
        $content = $content !== false ? $content : '';
        $reader = new SseLineReader();
        return iterator_to_array($reader->read([$content]), false);
    }
}
