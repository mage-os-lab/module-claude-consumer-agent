<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend\Provider;

use MageOS\AiShoppingAssistant\Api\Backend\StoreFactTitleResolverInterface;

final class StoreFactTitleResolver implements StoreFactTitleResolverInterface
{
    public function __construct(
        private readonly \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        private readonly \Magento\Cms\Api\BlockRepositoryInterface $blockRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function resolve(int $storeId, array $facts): array
    {
        $pageIdentifiers = [];
        $blockIdentifiers = [];
        foreach ($facts as $fact) {
            $source = is_array($fact) ? ($fact['source'] ?? null) : null;
            $value = is_array($fact) ? (string)($fact['value'] ?? '') : '';
            if ($source === 'cms_page' && $value !== '') {
                $pageIdentifiers[] = $value;
            }
            if ($source === 'cms_block' && $value !== '') {
                $blockIdentifiers[] = $value;
            }
        }

        $pageTitles = $pageIdentifiers !== [] ? $this->cmsPageTitles($storeId, $pageIdentifiers) : [];
        $blockTitles = $blockIdentifiers !== [] ? $this->cmsBlockTitles($storeId, $blockIdentifiers) : [];

        $resolved = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                continue;
            }
            $source = $fact['source'] ?? null;
            $value = (string)($fact['value'] ?? '');
            if ($source === 'cms_page') {
                $fact['title'] = $pageTitles[$value] ?? $value;
            }
            if ($source === 'cms_block') {
                $fact['title'] = $blockTitles[$value] ?? $value;
            }
            $resolved[] = $fact;
        }
        return $resolved;
    }

    private function cmsPageTitles(int $storeId, array $identifiers): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifiers, 'in')
            ->addFilter('is_active', 1)
            ->addFilter('store_id', [0, $storeId], 'in')
            ->create();

        $titles = [];
        foreach ($this->pageRepository->getList($criteria)->getItems() as $page) {
            $titles[$page->getIdentifier()] = (string)$page->getTitle();
        }
        return $titles;
    }

    private function cmsBlockTitles(int $storeId, array $identifiers): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifiers, 'in')
            ->addFilter('is_active', 1)
            ->addFilter('store_id', [0, $storeId], 'in')
            ->create();

        $titles = [];
        foreach ($this->blockRepository->getList($criteria)->getItems() as $block) {
            $titles[$block->getIdentifier()] = (string)$block->getTitle();
        }
        return $titles;
    }
}
