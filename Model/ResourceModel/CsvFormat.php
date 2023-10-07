<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CsvFormat extends AbstractDb
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_csv_format_resource_model';

    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init('banksync_csv_format', 'entity_id');
        $this->_useIsObjectNew = true;
    }
}
