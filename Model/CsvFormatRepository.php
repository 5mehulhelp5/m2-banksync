<?php

namespace Ibertrand\BankSync\Model;

use Ibertrand\BankSync\Model\ResourceModel\CsvFormat as ObjectResourceModel;
use Ibertrand\BankSync\Model\ResourceModel\CsvFormat\CollectionFactory;

/**
 * Class CsvFormatRepository
 *
 * @method CsvFormat getById(int $id)
 */
class CsvFormatRepository extends AbstractRepository
{
    /**
     * CsvFormatRepository constructor.
     *
     * @param CsvFormatFactory    $objectFactory
     * @param ObjectResourceModel $objectResourceModel
     * @param CollectionFactory   $collectionFactory
     */
    public function __construct(
        CsvFormatFactory    $objectFactory,
        ObjectResourceModel $objectResourceModel,
        CollectionFactory   $collectionFactory,
    ) {
        $this->objectFactory = $objectFactory;
        $this->objectResourceModel = $objectResourceModel;
        $this->collectionFactory = $collectionFactory;
    }
}
