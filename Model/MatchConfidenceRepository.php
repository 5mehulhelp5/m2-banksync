<?php

namespace Ibertrand\BankSync\Model;

use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence as ObjectResourceModel;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory;

/**
 * Class MatchConfidenceRepository
 *
 * @method MatchConfidence getById(int $id)
 */
class MatchConfidenceRepository extends AbstractRepository
{
    /**
     * MatchConfidenceRepository constructor.
     *
     * @param MatchConfidenceFactory $objectFactory
     * @param ObjectResourceModel    $objectResourceModel
     * @param CollectionFactory      $collectionFactory
     */
    public function __construct(
        MatchConfidenceFactory $objectFactory,
        ObjectResourceModel    $objectResourceModel,
        CollectionFactory      $collectionFactory,
    ) {
        $this->objectFactory = $objectFactory;
        $this->objectResourceModel = $objectResourceModel;
        $this->collectionFactory = $collectionFactory;
    }
}
