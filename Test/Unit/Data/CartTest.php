<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use PHPUnit\Framework\TestCase;

class CartTest extends TestCase
{
    public function testFindReturnsMatchingItem(): void
    {
        $itemOne = new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1);
        $itemTwo = new CartItem(productId: 'SKU-2', title: 'Two', price: 20.0, quantity: 2);
        $cart = new Cart(items: [$itemOne, $itemTwo]);

        $this->assertSame($itemTwo, $cart->find('SKU-2'));
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $cart = new Cart();

        $this->assertNull($cart->find('SKU-404'));
    }

    public function testIsEmpty(): void
    {
        $emptyCart = new Cart();
        $filledCart = new Cart(items: [new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 1)]);

        $this->assertTrue($emptyCart->isEmpty());
        $this->assertFalse($filledCart->isEmpty());
    }

    public function testItemCountSumsQuantities(): void
    {
        $cart = new Cart(items: [
            new CartItem(productId: 'SKU-1', title: 'One', price: 10.0, quantity: 2),
            new CartItem(productId: 'SKU-2', title: 'Two', price: 20.0, quantity: 3),
        ]);

        $this->assertSame(5, $cart->getItemCount());
    }

    public function testSubtotalSumsLineTotals(): void
    {
        $cart = new Cart(items: [
            new CartItem(productId: 'SKU-1', title: 'One', price: 9.995, quantity: 2),
            new CartItem(productId: 'SKU-2', title: 'Two', price: 5.0, quantity: 1),
        ]);

        $this->assertSame(24.99, $cart->getSubtotal());
    }

    public function testFromArrayBuildsItemsAndComputesTotals(): void
    {
        $cart = Cart::fromArray([
            'currency' => 'CAD',
            'items' => [
                ['product_id' => 'SKU-1', 'title' => 'One', 'price' => 10.0, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('CAD', $cart->getCurrency());
        $this->assertSame(2, $cart->getItemCount());
        $this->assertSame(20.0, $cart->getSubtotal());
        $this->assertNotNull($cart->find('SKU-1'));
    }
}
