<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Session;

use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Turn;
use MageOS\AiShoppingAssistant\Model\Session\TurnLog;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class TurnLogTest extends TestCase
{
    public function testRecordInsertsTheGivenRowUnchanged(): void
    {
        $row = [
            'session_id' => 'sess-1',
            'store_id' => 1,
            'turn_no' => 3,
            'model_id' => 'claude-sonnet-5',
            'rounds' => 2,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 10,
            'duration_ms' => 900,
            'stop_reason' => 'end_turn',
        ];
        $resource = $this->createMock(Turn::class);
        $resource->expects($this->once())->method('insert')->with($row);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $turnLog = new TurnLog($resource, $logger);
        $turnLog->record($row);
    }

    public function testInsertFailureIsSwallowedAndLoggedAsAWarning(): void
    {
        $resource = $this->createMock(Turn::class);
        $resource->method('insert')->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $turnLog = new TurnLog($resource, $logger);
        $turnLog->record(['session_id' => 'sess-1']);
    }
}
