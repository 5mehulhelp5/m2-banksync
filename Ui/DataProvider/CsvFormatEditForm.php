<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Model\ResourceModel\CsvFormat\CollectionFactory;
use Magento\Framework\App\Request\DataPersistor;
use Magento\Ui\DataProvider\AbstractDataProvider;

class CsvFormatEditForm extends AbstractDataProvider
{

    private DataPersistor $dataPersistor;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        DataPersistor $dataPersistor,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        $this->dataPersistor = $dataPersistor;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData()
    {
        $data = [];
        foreach ($this->collection as $item) {
            $data[$item->getId()] = $item->getData();
        }

        $persistedData = $this->dataPersistor->get('banksync_csvformat');
        if (!empty($persistedData)) {
            $item = $this->collection->getNewEmptyItem();
            $item->setData($persistedData);
            $data[$item->getId()] = $item->getData();
            $this->dataPersistor->clear('banksync_csvformat');
        }

        return $data;
    }

}
