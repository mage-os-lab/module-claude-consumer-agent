<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Gate;

use Magento\Framework\Lock\LockManagerInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\CartWrite;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Options;
use MageOS\AiShoppingAssistant\Model\Agent\Gate\Provenance;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class CartWriteTest extends TestCase
{
    private function buildGate(
        StorefrontBackendInterface&MockObject $backend,
        LockManagerInterface&MockObject $lockManager,
        ?LoggerInterface $logger = null
    ): CartWrite {
        return new CartWrite(
            $backend,
            $lockManager,
            new Options(new Sanitizer()),
            new Provenance(),
            new Serializer(new Fence(new Sanitizer())),
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    private function buildContext(string $sessionId = 's-1'): SessionContext
    {
        return new SessionContext($sessionId, null, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function seenState(string $productId): SessionState
    {
        $state = new SessionState();
        $state->rememberProducts([['product_id' => $productId, 'title' => 'Thing', 'price' => 9.0]]);
        return $state;
    }

    private function seenStateWithRequiredCustomOption(string $productId): SessionState
    {
        $state = new SessionState();
        $state->rememberProducts([[
            'product_id' => $productId,
            'title' => 'Alessi 9090 Espresso Maker',
            'price' => 175.0,
            'has_required_custom_options' => true,
            'custom_options' => [[
                'option_id' => 7,
                'title' => 'Size',
                'type' => 'drop_down',
                'required' => true,
                'values' => [
                    ['value_id' => 1, 'title' => '1 CUP', 'price' => 0.0, 'price_type' => 'fixed'],
                    ['value_id' => 2, 'title' => '3 CUP', 'price' => 15.0, 'price_type' => 'fixed'],
                ],
            ]],
        ]]);
        return $state;
    }

    public function testLockNameStaysUnderTheSixtyFourCharacterLimit(): void
    {
        $gate = $this->buildGate(
            $this->createMock(StorefrontBackendInterface::class),
            $this->createMock(LockManagerInterface::class)
        );
        $name = $gate->lockName(str_repeat('s', 100));
        $this->assertLessThan(64, strlen($name));
        $this->assertSame('aiagent:cart:' . str_repeat('s', 32), $name);
    }

    public function testAddReportsLockBusy(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $gate = $this->buildGate($this->createMock(StorefrontBackendInterface::class), $lockManager);
        $result = $gate->add($this->buildContext(), $this->seenState('p-1'), new AgentConfig(), 'p-1', 1);
        $this->assertTrue($result->isError);
        $this->assertSame('The cart is busy; try again in a moment.', $result->resultText);
    }

    public function testAddLockBusyLogsAWarning(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('cart lock timed out'));
        $gate = $this->buildGate($this->createMock(StorefrontBackendInterface::class), $lockManager, $logger);
        $gate->add($this->buildContext(), $this->seenState('p-1'), new AgentConfig(), 'p-1', 1);
    }

    public function testAddRefusesANewLineWhenTheCartIsFull(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $cart = new Cart([new CartItem('p-existing', 'Existing', 5.0, 1)]);
        $backend->method('getCart')->willReturn($cart);
        $backend->expects($this->never())->method('addToCart');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects($this->once())->method('unlock');
        $gate = $this->buildGate($backend, $lockManager);
        $config = new AgentConfig(maxCartLines: 1);
        $result = $gate->add($this->buildContext(), $this->seenState('p-1'), $config, 'p-1', 1);
        $this->assertTrue($result->isError);
        $this->assertSame('The cart is full.', $result->resultText);
    }

    public function testAddCapsQuantityAtThePerItemLimitAndEmitsCartUpdate(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart());
        $backend->expects($this->once())
            ->method('addToCart')
            ->with($this->anything(), 'p-1', 10)
            ->willReturn(new Cart([new CartItem('p-1', 'Thing', 9.0, 10)]));
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $config = new AgentConfig(maxQuantityPerItem: 10);
        $result = $gate->add($this->buildContext(), $this->seenState('p-1'), $config, 'p-1', 500);
        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Added p-1 x10', $result->resultText);
        $this->assertStringContainsString('capped at the per-item limit of 10', $result->resultText);
        $this->assertCount(1, $result->events);
        $this->assertSame('cart_update', $result->events[0]->type);
    }

    public function testAddRefusesWhenAlreadyAtThePerItemLimit(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart([new CartItem('p-1', 'Thing', 9.0, 10)]));
        $backend->expects($this->never())->method('addToCart');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $config = new AgentConfig(maxQuantityPerItem: 10);
        $result = $gate->add($this->buildContext(), $this->seenState('p-1'), $config, 'p-1', 1);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('already at the per-item limit of 10', $result->resultText);
    }

    public function testAddIsHeldByProvenanceBeforeTouchingTheLock(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('getCart');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->never())->method('lock');
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->add($this->buildContext(), new SessionState(), new AgentConfig(), 'p-1', 1);
        $this->assertSame(Provenance::NAME, $result->blocked);
    }

    public function testAddIsHeldByTheOptionsGateWhenARequiredCustomOptionIsMissing(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->expects($this->never())->method('getCart');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->never())->method('lock');
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->add(
            $this->buildContext(),
            $this->seenStateWithRequiredCustomOption('p-90'),
            new AgentConfig(),
            'p-90',
            1
        );
        $this->assertSame(Options::NAME, $result->blocked);
    }

    public function testAddPassesOptionsThroughToTheBackendWhenTheChoiceIsGiven(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart());
        $backend->expects($this->once())
            ->method('addToCart')
            ->with($this->anything(), 'p-90', 1, ['size' => '3 CUP'])
            ->willReturn(new Cart([new CartItem('p-90', 'Alessi 9090 Espresso Maker', 190.0, 1)]));
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->add(
            $this->buildContext(),
            $this->seenStateWithRequiredCustomOption('p-90'),
            new AgentConfig(),
            'p-90',
            1,
            ['size' => '3 CUP']
        );
        $this->assertFalse($result->isError);
        $this->assertNull($result->blocked);
    }

    public function testUpdatePassesWhenTheIdIsAlreadyACartLineEvenWithoutProvenance(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart([new CartItem('p-200', 'Stove', 64.5, 2)]));
        $backend->expects($this->once())
            ->method('updateCartItem')
            ->with($this->anything(), 'p-200', 4)
            ->willReturn(new Cart([new CartItem('p-200', 'Stove', 64.5, 4)]));
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->update($this->buildContext(), new SessionState(), new AgentConfig(), 'p-200', 4);
        $this->assertFalse($result->isError);
        $this->assertNull($result->blocked);
    }

    public function testUpdateIsHeldWhenNeitherProvenanceNorCartMembershipPass(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart());
        $backend->expects($this->never())->method('updateCartItem');
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->update($this->buildContext(), new SessionState(), new AgentConfig(), 'p-100', 2);
        $this->assertSame(Provenance::NAME, $result->blocked);
    }

    public function testRemoveReportsLockBusy(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $gate = $this->buildGate($this->createMock(StorefrontBackendInterface::class), $lockManager);
        $result = $gate->remove($this->buildContext(), $this->seenState('p-1'), 'p-1');
        $this->assertTrue($result->isError);
        $this->assertSame('The cart is busy; try again in a moment.', $result->resultText);
    }

    public function testRemoveEmitsCartUpdate(): void
    {
        $backend = $this->createMock(StorefrontBackendInterface::class);
        $backend->method('getCart')->willReturn(new Cart());
        $backend->expects($this->once())->method('removeFromCart')->willReturn(new Cart());
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $gate = $this->buildGate($backend, $lockManager);
        $result = $gate->remove($this->buildContext(), $this->seenState('p-1'), 'p-1');
        $this->assertFalse($result->isError);
        $this->assertStringStartsWith('Removed.', $result->resultText);
        $this->assertSame('cart_update', $result->events[0]->type);
    }
}
