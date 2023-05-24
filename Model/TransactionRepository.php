<?php

namespace Ibertrand\BankSync\Model;

use Ibertrand\BankSync\Model\ResourceModel\Transaction as ObjectResourceModel;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory;

/**
 * Class TransactionRepository
 *
 * @method Transaction getById(int $id)
 */
class TransactionRepository extends AbstractRepository
{
    /**
     * TransactionRepository constructor.
     *
     * @param TransactionFactory  $objectFactory
     * @param ObjectResourceModel $objectResourceModel
     * @param CollectionFactory   $collectionFactory
     */
    public function __construct(
        TransactionFactory  $objectFactory,
        ObjectResourceModel $objectResourceModel,
        CollectionFactory   $collectionFactory,
    ) {
        $this->objectFactory = $objectFactory;
        $this->objectResourceModel = $objectResourceModel;
        $this->collectionFactory = $collectionFactory;
    }
}
