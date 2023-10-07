<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Model\ResourceModel\CsvFormat\CollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class CsvFormatListing extends AbstractDataProvider
{

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $meta,
            $data
        );
    }
}
