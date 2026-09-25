<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Data\OptionSourceInterface;

final class CmsPages implements OptionSourceInterface
{
    public function __construct(
        private readonly \Magento\Cms\Api\PageRepositoryInterface $pageRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    public function toOptionArray(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(PageInterface::IS_ACTIVE, 1)
            ->create();
        $pages = $this->pageRepository->getList($searchCriteria)->getItems();
        $options = [];
        foreach ($pages as $page) {
            $options[] = ['value' => $page->getIdentifier(), 'label' => $page->getTitle()];
        }
        usort(
            $options,
            static fn (array $left, array $right): int => strcmp((string)$left['label'], (string)$right['label'])
        );
        return $options;
    }
}
