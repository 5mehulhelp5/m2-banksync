<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Ibertrand\BankSync\Model\TempTransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

class TempTransaction extends AbstractDb
{
    private TempTransactionFactory $tempTransactionFactory;

    public function __construct(
        Context                $context,
        TempTransactionFactory $tempTransactionFactory,
        $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
        $this->tempTransactionFactory = $tempTransactionFactory;
    }

    protected function _construct()
    {
        $this->_init('banksync_temp_transaction', 'entity_id');
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    public function deleteAll()
    {
        $connection = $this->getConnection();

        $connection->truncateTable($this->getMainTable());
    }

    public function fromTransaction(\Ibertrand\BankSync\Model\Transaction $transaction)
    {
        return $this->tempTransactionFactory->create()
            ->setPayerName($transaction->getPayerName())
            ->setTransactionDate($transaction->getTransactionDate())
            ->setPurpose($transaction->getPurpose())
            ->setAmount($transaction->getAmount())
            ->setHash($transaction->getHash());
    }
}
