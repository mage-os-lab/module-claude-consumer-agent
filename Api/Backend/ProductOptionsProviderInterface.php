<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

use Magento\Catalog\Api\Data\ProductInterface;

interface ProductOptionsProviderInterface
{
    /**
     * @return array{
     *     options: array<string, int>,
     *     required: bool,
     *     titles: string[],
     *     custom_options: array<int, array{
     *         option_id: int,
     *         title: string,
     *         type: string,
     *         required: bool,
     *         values: array<int, array{value_id: int, title: string, price: float, price_type: string}>
     *     }>
     * }
     */
    public function summarize(ProductInterface $product): array;
}
