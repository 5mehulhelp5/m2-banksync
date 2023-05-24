<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class MatchConfidence extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('banksync_temptransaction_confidence', 'entity_id');
    }
}
