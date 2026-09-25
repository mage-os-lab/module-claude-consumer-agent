<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Model\Product as MagentoProduct;
use MageOS\AiShoppingAssistant\Model\Backend\BuyRequestBuilder;
use PHPUnit\Framework\TestCase;

final class BuyRequestBuilderTest extends TestCase
{
    private function product(int $id): MagentoProduct
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn($id);
        return $product;
    }

    public function testBuildsPlainRequest(): void
    {
        $builder = new BuyRequestBuilder();
        $product = $this->product(42);

        $request = $builder->build($product, 3, []);

        $this->assertSame(['qty' => 3, 'product' => 42], $request->getData());
    }

    public function testBuildsRequestWithSuperAttribute(): void
    {
        $builder = new BuyRequestBuilder();
        $product = $this->product(42);

        $request = $builder->build($product, 1, ['super_attribute' => ['93' => '15']]);

        $this->assertSame(
            ['qty' => 1, 'product' => 42, 'super_attribute' => ['93' => '15']],
            $request->getData()
        );
    }

    public function testEmptySuperAttributeIsOmitted(): void
    {
        $builder = new BuyRequestBuilder();
        $product = $this->product(42);

        $request = $builder->build($product, 1, ['super_attribute' => []]);

        $this->assertSame(['qty' => 1, 'product' => 42], $request->getData());
    }

    public function testBuildsRequestWithOptions(): void
    {
        $builder = new BuyRequestBuilder();
        $product = $this->product(7);

        $request = $builder->build($product, 2, ['options' => ['1' => 'engraved text']]);

        $this->assertSame(
            ['qty' => 2, 'product' => 7, 'options' => ['1' => 'engraved text']],
            $request->getData()
        );
    }
}
