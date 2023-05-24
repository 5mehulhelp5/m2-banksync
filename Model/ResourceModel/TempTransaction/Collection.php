<?php

namespace Ibertrand\BankSync\Model\ResourceModel\TempTransaction;

use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as TempTransactionResource;
use Ibertrand\BankSync\Model\TempTransaction as TempTransactionModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            TempTransactionModel::class,
            TempTransactionResource::class
        );
    }
}
