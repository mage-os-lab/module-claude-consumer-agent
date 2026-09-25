<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product as MagentoProduct;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\HelperImageUrl;
use PHPUnit\Framework\TestCase;

final class HelperImageUrlTest extends TestCase
{
    public function testDelegatesToTheImageHelperInitAndGetUrl(): void
    {
        $product = $this->createMock(MagentoProduct::class);

        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->expects($this->once())
            ->method('init')
            ->with($product, 'category_page_grid')
            ->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.test/img.jpg');

        $provider = new HelperImageUrl($imageHelper);

        $this->assertSame(
            'https://example.test/img.jpg',
            $provider->forProduct($product, 'category_page_grid', 1)
        );
    }
}
