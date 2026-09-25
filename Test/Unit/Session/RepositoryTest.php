<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Session;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Session\IdGenerator;
use MageOS\AiShoppingAssistant\Model\Session\Repository;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class RepositoryTest extends TestCase
{
    private function buildContext(?int $customerId, int $quoteId, int $storeId): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('', $customerId, $quoteId, $storeId, $page, new \DateTimeImmutable());
    }

    private function buildRepository(Session&MockObject $resource, LoggerInterface&MockObject $logger): Repository
    {
        return new Repository($resource, new IdGenerator(), $logger);
    }

    public function testNullSessionIdMintsNewSession(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->never())->method('load');
        $resource->expects($this->once())->method('insert');
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(null, 5, 1);
        $binding = $repository->bind(null, $ctx);

        $this->assertTrue($binding->isNew);
        $this->assertSame(0, $binding->expectedVersion);
        $this->assertSame(64, strlen($binding->sessionId));
    }

    public function testBindMintsANewSessionWithTheGivenSurface(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->never())->method('load');
        $captured = null;
        $resource->method('insert')->willReturnCallback(function (array $row) use (&$captured): void {
            $captured = $row;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(null, 5, 1);
        $binding = $repository->bind(null, $ctx, 'side_cart');

        $this->assertTrue($binding->isNew);
        $this->assertSame('side_cart', $captured['surface']);
    }

    public function testForeignCustomerMintsNewSession(): void
    {
        $sessionId = str_repeat('a', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => 999,
            'quote_id' => 5,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ]);
        $resource->expects($this->once())->method('insert');
        $resource->expects($this->never())->method('update');
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(42, 5, 1);
        $binding = $repository->bind($sessionId, $ctx);

        $this->assertTrue($binding->isNew);
        $this->assertNotSame($sessionId, $binding->sessionId);
    }

    public function testForeignQuoteMintsNewSession(): void
    {
        $sessionId = str_repeat('b', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => null,
            'quote_id' => 555,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ]);
        $resource->expects($this->once())->method('insert');
        $resource->expects($this->never())->method('update');
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(null, 10, 1);
        $binding = $repository->bind($sessionId, $ctx);

        $this->assertTrue($binding->isNew);
    }

    public function testStoreMismatchMintsNewSession(): void
    {
        $sessionId = str_repeat('c', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => 42,
            'quote_id' => 5,
            'store_id' => 2,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ]);
        $resource->expects($this->once())->method('insert');
        $resource->expects($this->never())->method('update');
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(42, 5, 1);
        $binding = $repository->bind($sessionId, $ctx);

        $this->assertTrue($binding->isNew);
    }

    public function testGuestSignInUpdatesCustomerId(): void
    {
        $sessionId = str_repeat('d', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => null,
            'quote_id' => 77,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 3,
            'turns' => 2,
        ]);
        $resource->expects($this->once())
            ->method('update')
            ->with($sessionId, ['customer_id' => 42]);
        $resource->expects($this->never())->method('insert');
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(42, 77, 1);
        $binding = $repository->bind($sessionId, $ctx);

        $this->assertFalse($binding->isNew);
        $this->assertSame($sessionId, $binding->sessionId);
        $this->assertSame(3, $binding->expectedVersion);
        $this->assertSame(42, $binding->row['customer_id']);
    }

    public function testFindWithoutASessionIdReturnsNullAndTouchesNothing(): void
    {
        $resource = $this->createMock(Session::class);
        $resource->expects($this->never())->method('load');
        $resource->expects($this->never())->method('insert');
        $resource->expects($this->never())->method('update');
        $repository = $this->buildRepository($resource, $this->createMock(LoggerInterface::class));

        $this->assertNull($repository->find(null, $this->buildContext(null, 5, 1)));
        $this->assertNull($repository->find('not-a-session-id', $this->buildContext(null, 5, 1)));
    }

    public function testFindWithAnUnknownSessionIdReturnsNullAndInsertsNothing(): void
    {
        $sessionId = str_repeat('1', 64);
        $resource = $this->createMock(Session::class);
        $resource->expects($this->once())->method('load')->with($sessionId)->willReturn(null);
        $resource->expects($this->never())->method('insert');
        $resource->expects($this->never())->method('update');
        $repository = $this->buildRepository($resource, $this->createMock(LoggerInterface::class));

        $this->assertNull($repository->find($sessionId, $this->buildContext(null, 5, 1)));
    }

    public function testFindReturnsNullForAForeignOrMismatchedRowWithoutWriting(): void
    {
        $sessionId = str_repeat('2', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => 999,
            'quote_id' => 5,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ]);
        $resource->expects($this->never())->method('insert');
        $resource->expects($this->never())->method('update');
        $repository = $this->buildRepository($resource, $this->createMock(LoggerInterface::class));

        $this->assertNull($repository->find($sessionId, $this->buildContext(42, 5, 1)));
        $this->assertNull($repository->find($sessionId, $this->buildContext(999, 5, 2)));
    }

    public function testFindReturnsTheOwnedRowWithoutWriting(): void
    {
        $sessionId = str_repeat('3', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => 42,
            'quote_id' => 5,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 3,
            'turns' => 2,
        ]);
        $resource->expects($this->never())->method('insert');
        $resource->expects($this->never())->method('update');
        $repository = $this->buildRepository($resource, $this->createMock(LoggerInterface::class));

        $binding = $repository->find($sessionId, $this->buildContext(42, 5, 1));

        $this->assertNotNull($binding);
        $this->assertSame($sessionId, $binding->sessionId);
        $this->assertFalse($binding->isNew);
        $this->assertSame(3, $binding->expectedVersion);
        $this->assertSame(2, $binding->row['turns']);
    }

    public function testFindDoesNotClaimAGuestRowForASignedInCustomer(): void
    {
        $sessionId = str_repeat('4', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('load')->willReturn([
            'session_id' => $sessionId,
            'customer_id' => null,
            'quote_id' => 77,
            'store_id' => 1,
            'surface' => 'overlay',
            'state' => '{}',
            'version' => 0,
            'turns' => 0,
        ]);
        $resource->expects($this->never())->method('insert');
        $resource->expects($this->never())->method('update');
        $repository = $this->buildRepository($resource, $this->createMock(LoggerInterface::class));

        $binding = $repository->find($sessionId, $this->buildContext(42, 77, 1));

        $this->assertNotNull($binding);
        $this->assertNull($binding->row['customer_id']);
    }

    public function testSaveConflictReturnsFalseAndLogs(): void
    {
        $sessionId = str_repeat('e', 64);
        $resource = $this->createMock(Session::class);
        $resource->method('updateWhereVersion')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(42, 5, 1);
        $binding = new \MageOS\AiShoppingAssistant\Model\Session\Binding(
            $sessionId,
            ['turns' => 4],
            new SessionState(),
            false,
            2,
            $ctx
        );

        $result = $repository->save($binding, new SessionState());

        $this->assertFalse($result);
        $this->assertSame(2, $binding->expectedVersion);
    }

    public function testSaveTwiceOnSameBindingIncrementsTurnsAndVersion(): void
    {
        $sessionId = str_repeat('f', 64);
        $resource = $this->createMock(Session::class);
        $calls = [];
        $resource->method('updateWhereVersion')->willReturnCallback(
            static function (string $id, array $data, int $expectedVersion) use (&$calls): int {
                $calls[] = $data;
                return 1;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $repository = $this->buildRepository($resource, $logger);

        $ctx = $this->buildContext(42, 5, 1);
        $binding = new \MageOS\AiShoppingAssistant\Model\Session\Binding(
            $sessionId,
            ['turns' => 0, 'version' => 0],
            new SessionState(),
            true,
            0,
            $ctx
        );

        $this->assertTrue($repository->save($binding, new SessionState()));
        $this->assertTrue($repository->save($binding, new SessionState()));

        $this->assertSame(1, $calls[0]['turns']);
        $this->assertSame(1, $calls[0]['version']);
        $this->assertSame(2, $calls[1]['turns']);
        $this->assertSame(2, $calls[1]['version']);
        $this->assertSame(2, $binding->expectedVersion);
        $this->assertSame(2, $binding->row['turns']);
    }
}
