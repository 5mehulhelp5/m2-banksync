<?php

namespace Ibertrand\BankSync\Helper;

use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\Transaction;
use Magento\Framework\App\Helper\AbstractHelper;

class Hashes extends AbstractHelper
{
    /**
     * @param TempTransaction|Transaction $transaction
     * @return string
     */
    public function calculateHash(TempTransaction|Transaction $transaction): string
    {
        return sha1(
            implode(
                '|',
                [
                    $transaction->getPayerName(),
                    number_format($transaction->getAmount(), 2, '.', ''),
                    $transaction->getPurpose(),
                    date('Y-m-d H:i:s', strtotime($transaction->getTransactionDate())),
                ]
            )
        );
    }

}
