<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Dunning extends AbstractDb
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_dunning_resource_model';

    /**
     * Initialize resource model.
     */
    protected function _construct()
    {
        $this->_init('banksync_dunning', 'entity_id');
        $this->_useIsObjectNew = true;
    }

}
