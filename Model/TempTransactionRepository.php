<?php

namespace Ibertrand\BankSync\Model;

use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as ObjectResourceModel;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory;

/**
 * Class TempTransactionRepository
 *
 * @method TempTransaction getById(int $id)
 */
class TempTransactionRepository extends AbstractRepository
{
    /**
     * TempTransactionRepository constructor.
     *
     * @param TempTransactionFactory $objectFactory
     * @param ObjectResourceModel         $objectResourceModel
     * @param CollectionFactory           $collectionFactory
     */
    public function __construct(
        TempTransactionFactory $objectFactory,
        ObjectResourceModel         $objectResourceModel,
        CollectionFactory           $collectionFactory,
    ) {
        $this->objectFactory = $objectFactory;
        $this->objectResourceModel = $objectResourceModel;
        $this->collectionFactory = $collectionFactory;
    }
}
