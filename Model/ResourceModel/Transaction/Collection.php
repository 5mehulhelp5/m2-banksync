<?php

namespace Ibertrand\BankSync\Model\ResourceModel\Transaction;

use Ibertrand\BankSync\Model\Transaction as TransactionModel;
use Ibertrand\BankSync\Model\ResourceModel\Transaction as TransactionResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            TransactionModel::class,
            TransactionResource::class
        );
    }
}
