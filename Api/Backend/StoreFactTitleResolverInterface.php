<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Api\Backend;

interface StoreFactTitleResolverInterface
{
    /**
     * Returns $facts with a 'title' key added to every cms_page and cms_block row,
     * resolved from the CMS page or block the row's value identifies, falling back to
     * that identifier when nothing resolves.
     */
    public function resolve(int $storeId, array $facts): array;
}
