<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Client;

/**
 * A stream whose reads fail without ever setting the timed_out flag, so a test can tell a
 * genuine read failure apart from the timed-out read that a chunked response produces.
 */
final class ReadFailStreamWrapper
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): bool
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
