<?php

namespace Ibertrand\BankSync\Model\ResourceModel\MatchConfidence;

use Ibertrand\BankSync\Model\MatchConfidence as MatchConfidenceModel;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence as MatchConfidenceResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(
            MatchConfidenceModel::class,
            MatchConfidenceResource::class
        );
    }
}
