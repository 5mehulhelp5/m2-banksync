<?php

namespace Ibertrand\BankSync\Model\ResourceModel;

use Ibertrand\BankSync\Model\TransactionFactory;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

class Transaction extends AbstractDb
{
    private TransactionFactory $transactionFactory;

    public function __construct(
        Context            $context,
        TransactionFactory $transactionFactory,
        $connectionName = null
    ) {
        $this->transactionFactory = $transactionFactory;
        parent::__construct($context, $connectionName);
    }

    public function fromTempTransaction(\Ibertrand\BankSync\Model\TempTransaction $tempTransaction)
    {
        return $this->transactionFactory->create()
            ->setPayerName($tempTransaction->getPayerName())
            ->setTransactionDate($tempTransaction->getTransactionDate())
            ->setPurpose($tempTransaction->getPurpose())
            ->setAmount($tempTransaction->getAmount())
            ->setDocumentType($tempTransaction->getDocumentType());
    }

    protected function _construct()
    {
        $this->_init('banksync_transaction', 'entity_id');
    }
}
