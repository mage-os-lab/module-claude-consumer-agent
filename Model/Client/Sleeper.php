<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Client;

/**
 * Not final so PHPUnit 9.6 can mock it.
 */
class Sleeper
{
    public function sleep(float $seconds): void
    {
        usleep((int)($seconds * 1_000_000));
    }
}
