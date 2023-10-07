<?php

namespace Ibertrand\BankSync\Model\ResourceModel\CsvFormat;

use Ibertrand\BankSync\Model\CsvFormat as Model;
use Ibertrand\BankSync\Model\ResourceModel\CsvFormat as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_csv_format_collection';

    /**
     * Initialize collection model.
     */
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
