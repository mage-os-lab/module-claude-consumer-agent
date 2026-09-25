<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Console;

use Magento\Framework\Console\Cli;
use MageOS\AiShoppingAssistant\Console\Command\SessionPurge;
use MageOS\AiShoppingAssistant\Model\Session\ResourceModel\Session;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SessionPurgeTest extends TestCase
{
    private Session|MockObject $sessionResource;

    protected function setUp(): void
    {
        $this->sessionResource = $this->createMock(Session::class);
    }

    private function buildTester(): CommandTester
    {
        $command = new SessionPurge($this->sessionResource);
        return new CommandTester($command);
    }

    public function testAllWithoutForceIsRefused(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteAll');
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--all' => true]);
        $this->assertSame(Cli::RETURN_FAILURE, $exitCode);
        $this->assertStringContainsString('--force', $tester->getDisplay());
    }

    public function testAllWithForceDeletesEverySession(): void
    {
        $this->sessionResource->expects($this->once())
            ->method('deleteAll')
            ->willReturn(7);
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--all' => true, '--force' => true]);
        $this->assertSame(Cli::RETURN_SUCCESS, $exitCode);
        $this->assertStringContainsString('Deleted 7 session(s).', $tester->getDisplay());
    }

    public function testDryRunPrintsCountAndDeletesNothing(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteAll');
        $this->sessionResource->expects($this->never())->method('deleteOlderThan');
        $this->sessionResource->expects($this->never())->method('deleteByCustomer');
        $this->sessionResource->expects($this->once())
            ->method('countAll')
            ->willReturn(5);
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--all' => true, '--force' => true, '--dry-run' => true]);
        $this->assertSame(Cli::RETURN_SUCCESS, $exitCode);
        $this->assertStringContainsString('5 session(s) would be deleted.', $tester->getDisplay());
    }

    public function testDryRunWithOlderThanUsesCountOlderThan(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteOlderThan');
        $this->sessionResource->expects($this->once())
            ->method('countOlderThan')
            ->willReturn(3);
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--older-than' => '30', '--dry-run' => true]);
        $this->assertSame(Cli::RETURN_SUCCESS, $exitCode);
        $this->assertStringContainsString('3 session(s) would be deleted.', $tester->getDisplay());
    }

    public function testOlderThanDeletesMatchingSessions(): void
    {
        $this->sessionResource->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeInterface::class))
            ->willReturn(4);
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--older-than' => '30']);
        $this->assertSame(Cli::RETURN_SUCCESS, $exitCode);
        $this->assertStringContainsString('Deleted 4 session(s).', $tester->getDisplay());
    }

    public function testCustomerDeletesMatchingSessions(): void
    {
        $this->sessionResource->expects($this->once())
            ->method('deleteByCustomer')
            ->with(42)
            ->willReturn(2);
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--customer' => '42']);
        $this->assertSame(Cli::RETURN_SUCCESS, $exitCode);
        $this->assertStringContainsString('Deleted 2 session(s).', $tester->getDisplay());
    }

    public function testOlderThanZeroIsRefusedBeforeAnyPurge(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteOlderThan');
        $this->sessionResource->expects($this->never())->method('countOlderThan');
        $this->sessionResource->expects($this->never())->method('deleteAll');
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--older-than' => '0']);
        $this->assertSame(Cli::RETURN_FAILURE, $exitCode);
        $this->assertStringContainsString('--older-than', $tester->getDisplay());
    }

    public function testNonNumericOrNegativeOlderThanIsRefused(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteOlderThan');
        $this->sessionResource->expects($this->never())->method('countOlderThan');
        foreach (['abc', '-5', '1.5', ' 7', ''] as $value) {
            $tester = $this->buildTester();
            $exitCode = $tester->execute(['--older-than' => $value, '--dry-run' => true]);
            $this->assertSame(Cli::RETURN_FAILURE, $exitCode, sprintf('value "%s" was accepted', $value));
        }
    }

    public function testCustomerMustBeAPositiveInteger(): void
    {
        $this->sessionResource->expects($this->never())->method('deleteByCustomer');
        $this->sessionResource->expects($this->never())->method('countByCustomer');
        foreach (['0', 'abc', '-1', ''] as $value) {
            $tester = $this->buildTester();
            $exitCode = $tester->execute(['--customer' => $value]);
            $this->assertSame(Cli::RETURN_FAILURE, $exitCode, sprintf('value "%s" was accepted', $value));
            $this->assertStringContainsString('--customer', $tester->getDisplay());
        }
    }

    public function testNoSelectorIsRefused(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute([]);
        $this->assertSame(Cli::RETURN_FAILURE, $exitCode);
    }

    public function testMultipleSelectorsAreRefused(): void
    {
        $tester = $this->buildTester();
        $exitCode = $tester->execute(['--older-than' => '30', '--customer' => '42']);
        $this->assertSame(Cli::RETURN_FAILURE, $exitCode);
    }
}
