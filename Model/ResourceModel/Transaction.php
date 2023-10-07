<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\Transaction as TransactionModel;
use Ibertrand\BankSync\Model\TransactionFactory;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

class Transaction extends AbstractDb
{
    protected TransactionFactory $transactionFactory;

    public function __construct(
        Context            $context,
        TransactionFactory $transactionFactory,
        $connectionName = null,
    ) {
        $this->transactionFactory = $transactionFactory;
        parent::__construct($context, $connectionName);
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return TransactionModel
     */
    public function fromTempTransaction(TempTransaction $tempTransaction): TransactionModel
    {
        return $this->transactionFactory->create()
            ->setPayerName($tempTransaction->getPayerName())
            ->setTransactionDate($tempTransaction->getTransactionDate())
            ->setPurpose($tempTransaction->getPurpose())
            ->setAmount($tempTransaction->getAmount())
            ->setComment($tempTransaction->getComment())
            ->setDocumentType($tempTransaction->getDocumentType())
            ->setPartialHash($tempTransaction->getPartialHash())
            ->setHash($tempTransaction->getHash());
    }

    protected function _construct()
    {
        $this->_init('banksync_transaction', 'entity_id');
    }
}
