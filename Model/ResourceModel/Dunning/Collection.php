<?php

namespace Ibertrand\BankSync\Model\ResourceModel\Dunning;

use Ibertrand\BankSync\Model\Dunning as Model;
use Ibertrand\BankSync\Model\ResourceModel\Dunning as ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_dunning_collection';

    /**
     * Initialize collection model.
     */
    protected function _construct()
    {
        $this->_init(Model::class, ResourceModel::class);
    }
}
