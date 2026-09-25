<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Catalog\Model\Category;
use Magento\Framework\Data\OptionSourceInterface;

final class CategoryTree implements OptionSourceInterface
{
    private const MIN_LEVEL = 2;
    private const MAX_LEVEL = 4;

    private ?array $options = null;

    public function __construct(
        private readonly \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $collectionFactory
    ) {
    }

    public function toOptionArray(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }

        $rows = $this->loadRows();

        $options = [];
        foreach ($rows as $id => $row) {
            $options[] = ['value' => (string)$id, 'label' => $this->buildLabel($row, $rows)];
        }

        usort(
            $options,
            static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label'])
        );

        $this->options = $options;
        return $this->options;
    }

    private function loadRows(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->setStoreId(0);
        $collection->addAttributeToSelect(['name', 'is_active']);
        $collection->addFieldToFilter('level', ['from' => self::MIN_LEVEL, 'to' => self::MAX_LEVEL]);
        $collection->addFieldToFilter('is_active', 1);
        $collection->addAttributeToSort('path', 'ASC');

        $rows = [];
        foreach ($collection as $item) {
            $rows[(int)$item->getId()] = $this->rowFromItem($item);
        }
        return $rows;
    }

    private function rowFromItem(Category $item): array
    {
        return [
            'name' => (string)$item->getName(),
            'path' => (string)$item->getPath(),
        ];
    }

    private function buildLabel(array $row, array $rows): string
    {
        $segments = explode('/', $row['path']);
        array_pop($segments);

        $names = [];
        foreach ($segments as $segment) {
            $ancestorId = (int)$segment;
            if (isset($rows[$ancestorId])) {
                $names[] = $rows[$ancestorId]['name'];
            }
        }
        $names[] = $row['name'];

        return implode(' / ', $names);
    }
}
