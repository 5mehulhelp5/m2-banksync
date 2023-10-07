<?php

namespace Ibertrand\BankSync\Model\Config\Source;

use Ibertrand\BankSync\Model\ResourceModel\CsvFormat\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class CsvFormats implements OptionSourceInterface
{
    private array $data;

    private CollectionFactory $collectionFactory;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(CollectionFactory $collectionFactory)
    {
        $this->collectionFactory = $collectionFactory;
    }


    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        $result = [];
        foreach ($this->toArray() as $key => $value) {
            $result[] = [
                'value' => $key,
                'label' => $value,
            ];
        }
        return $result;
    }

    /**
     * @return string[]
     */
    public function toArray(): array
    {
        if (!isset($this->data)) {
            $collection = $this->collectionFactory->create();
            $this->data = [];
            foreach ($collection as $item) {
                $this->data[$item->getId()] = $item->getName();
            }
        }
        return $this->data;
    }
}
