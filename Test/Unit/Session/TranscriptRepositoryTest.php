<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Session;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Message;
use MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository;
use PHPUnit\Framework\TestCase;

final class TranscriptRepositoryTest extends TestCase
{
    public function testAppendInsertsInOrderInsideTransaction(): void
    {
        $callOrder = [];
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())->method('beginTransaction')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'begin';
            });
        $adapter->expects($this->once())->method('commit')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'commit';
            });
        $adapter->expects($this->never())->method('rollBack');

        $connection = $this->createMock(ResourceConnection::class);
        $connection->method('getConnection')->willReturn($adapter);

        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ];

        $resource = $this->createMock(Message::class);
        $resource->expects($this->once())->method('insertMany')
            ->with('session-1', $messages)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'insertMany';
            });

        $repository = new TranscriptRepository($resource, $connection);
        $repository->append('session-1', $messages);

        $this->assertSame(['begin', 'insertMany', 'commit'], $callOrder);
    }

    public function testRewriteDeletesThenInserts(): void
    {
        $callOrder = [];
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->expects($this->once())->method('beginTransaction');
        $adapter->expects($this->once())->method('commit');
        $adapter->expects($this->never())->method('rollBack');

        $connection = $this->createMock(ResourceConnection::class);
        $connection->method('getConnection')->willReturn($adapter);

        $messages = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'compacted']]],
        ];

        $resource = $this->createMock(Message::class);
        $resource->expects($this->once())->method('deleteBySession')
            ->with('session-2')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'delete';
            });
        $resource->expects($this->once())->method('insertMany')
            ->with('session-2', $messages)
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'insert';
            });

        $repository = new TranscriptRepository($resource, $connection);
        $repository->rewrite('session-2', $messages);

        $this->assertSame(['delete', 'insert'], $callOrder);
    }

    public function testLoadDecodesJson(): void
    {
        $adapter = $this->createMock(AdapterInterface::class);
        $connection = $this->createMock(ResourceConnection::class);
        $connection->method('getConnection')->willReturn($adapter);

        $resource = $this->createMock(Message::class);
        $resource->method('loadBySession')->with('session-3')->willReturn([
            ['message_id' => 1, 'session_id' => 'session-3', 'role' => 'user', 'content' => '[{"type":"text","text":"hi"}]'],
            ['message_id' => 2, 'session_id' => 'session-3', 'role' => 'assistant', 'content' => '[{"type":"text","text":"hello"}]'],
        ]);

        $repository = new TranscriptRepository($resource, $connection);
        $messages = $repository->load('session-3');

        $this->assertSame([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hello']]],
        ], $messages);
    }

    public function testCountTokensEstimateIsCharsDividedByFour(): void
    {
        $connection = $this->createMock(ResourceConnection::class);
        $resource = $this->createMock(Message::class);
        $repository = new TranscriptRepository($resource, $connection);

        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]]];
        $json = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertSame((int)(strlen($json) / 4), $repository->countTokensEstimate($messages));
    }
}
