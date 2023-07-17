<?php

namespace Ibertrand\BankSync\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * @method int getEntityId()
 * @method Transaction setEntityId(int $entityId)
 * @method string getTransactionDate()
 * @method Transaction setTransactionDate(string $transactionDate)
 * @method string getPayerName()
 * @method Transaction setPayerName(string $payerName)
 * @method string getPurpose()
 * @method Transaction setPurpose(string $purpose)
 * @method float getAmount()
 * @method Transaction setAmount(float $amount)
 * @method string|null getComment()
 * @method $this setComment(string $comment)
 * @method string|null getHash()
 * @method $this setHash(string $hash)
 * @method string|null getPartialHash()
 * @method Transaction setPartialHash(string|null $partialTempTransaction)
 * @method int getDocumentId()
 * @method Transaction setDocumentId(int $documentId)
 * @method string getDocumentType()
 * @method Transaction setDocumentType(string $documentType)
 * @method int getMatchConfidence()
 * @method Transaction setMatchConfidence(int $matchConfidence)
 * @method string getCreatedAt()
 * @method Transaction setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method Transaction setUpdatedAt(string $updatedAt)
 */
class Transaction extends AbstractModel
{
    protected function _construct()
    {
        $this->_init('Ibertrand\BankSync\Model\ResourceModel\Transaction');
    }
}
