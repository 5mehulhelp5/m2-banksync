<?php

namespace Ibertrand\BankSync\Model;

use Ibertrand\BankSync\Model\ResourceModel\Dunning as ObjectResourceModel;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory;

/**
 * Class MatchConfidenceRepository
 *
 * @method Dunning getById(int $id)
 */
class DunningRepository extends AbstractRepository
{
    /**
     * MatchConfidenceRepository constructor.
     *
     * @param DunningFactory $objectFactory
     * @param ObjectResourceModel    $objectResourceModel
     * @param CollectionFactory      $collectionFactory
     */
    public function __construct(
        DunningFactory $objectFactory,
        ObjectResourceModel    $objectResourceModel,
        CollectionFactory      $collectionFactory,
    ) {
        $this->objectFactory = $objectFactory;
        $this->objectResourceModel = $objectResourceModel;
        $this->collectionFactory = $collectionFactory;
    }
}
