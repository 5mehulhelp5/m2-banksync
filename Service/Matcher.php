<?php

namespace Ibertrand\BankSync\Service;

use Exception;
use Ibertrand\BankSync\Helper\Data;
use Ibertrand\BankSync\Model\MatchConfidenceFactory;
use Ibertrand\BankSync\Model\MatchConfidenceRepository;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as TempTransactionResource;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\Collection as TempTransactionCollection;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory as TempTransactionCollectionFactory;
use Ibertrand\BankSync\Model\TempTransaction;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException as CouldNotDeleteExceptionAlias;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment;
use Psr\Log\LoggerInterface;

class Matcher
{
    protected TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    protected TempTransactionResource $tempTransactionResource;
    protected LoggerInterface $logger;
    private InvoiceCollectionFactory $invoiceCollectionFactory;
    private CreditmemoCollectionFactory $creditmemoCollectionFactory;
    private Data $helper;
    private MatchConfidenceFactory $matchConfidenceFactory;
    private MatchConfidenceRepository $matchConfidenceRepository;
    private MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    private Payment $paymentResource;
    /**
     * @var callable
     */
    private $progressCallBack;

    public function __construct(
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TempTransactionResource          $transactionResource,
        LoggerInterface                  $logger,
        InvoiceCollectionFactory         $invoiceCollectionFactory,
        CreditmemoCollectionFactory      $creditmemoCollectionFactory,
        MatchConfidenceFactory           $matchConfidenceFactory,
        MatchConfidenceRepository        $matchConfidenceRepository,
        MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory,
        Payment                          $paymentResource,
        Data                             $helper,
    ) {
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->tempTransactionResource = $transactionResource;
        $this->logger = $logger;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->matchConfidenceFactory = $matchConfidenceFactory;
        $this->matchConfidenceRepository = $matchConfidenceRepository;
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->paymentResource = $paymentResource;
        $this->helper = $helper;
    }

    /**
     * @param callable $progressCallBack
     *
     * @return void
     */
    public function setProgressCallBack(callable $progressCallBack): void
    {
        $this->progressCallBack = $progressCallBack;
    }

    /**
     * @param int $current
     * @param int $total
     *
     * @return void
     */
    private function progress(int $current, int $total): void
    {
        if ($this->progressCallBack) {
            call_user_func($this->progressCallBack, $current, $total);
        }
    }

    /**
     * @param TempTransaction $tempTransaction
     *
     * @return int[]
     * @throws LocalizedException
     */
    private function getDocumentConfidences(TempTransaction $tempTransaction): array
    {
        $collection = $tempTransaction->getAmount() >= 0
            ? $this->invoiceCollectionFactory->create()
            : $this->creditmemoCollectionFactory->create();

        $amount = abs($tempTransaction->getAmount());
        $amountThreshold = $this->helper->getAmountThreshold();
        $latestDate = date(
            'Y-m-d H:i:s',
            strtotime($tempTransaction->getTransactionDate()) + $this->helper->getDateThreshold() * 86400
        );

        $collection
            ->addFieldToFilter('state', ['neq' => 'canceled'])
            ->addFieldToFilter('main_table.created_at', ['lteq' => $latestDate])
            ->addFieldToFilter('main_table.created_at', ['gteq' => $this->helper->getStartDate()])
            ->addFieldToFilter('grand_total', ['gteq' => $amount - $amountThreshold])
            ->addFieldToFilter('grand_total', ['lteq' => $amount + $amountThreshold]);

        $condition = $collection->getConnection()->quoteInto(
            'tt.document_id = main_table.entity_id and tt.document_type = ?',
            $tempTransaction->getDocumentType(),
        );
        $collection->getSelect()->joinLeft(['tt' => 'banksync_transaction'], $condition, '');
        $collection->getSelect()->where('tt.document_id is null');

        $paymentMethods = $this->helper->getPaymentMethods();
        if (!empty($paymentMethods)) {
            $collection->getSelect()->joinLeft(
                ['p' => $this->paymentResource->getMainTable()],
                'main_table.order_id = p.parent_id',
                ''
            );
            $where = $collection->getConnection()->quoteInto('p.method in (?)', $paymentMethods);
            $collection->getSelect()->where($where);
        }

        $minConfidence = $this->helper->getMinConfidenceThreshold();
        $confidences = [];
        foreach ($collection as $document) {
            /** @var Invoice|Creditmemo $document */
            $confidence = $this->helper->getMatchConfidence($tempTransaction, $document);
            if ($confidence >= $minConfidence) {
                $confidences[$document->getId()] = $confidence;
            }
        }
        return $confidences;
    }

    /**
     * @param TempTransaction $tempTransaction
     *
     * @return int
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws CouldNotDeleteExceptionAlias
     */
    private function processTempTransaction(TempTransaction $tempTransaction): int
    {
        $this->deleteConfidences($tempTransaction);
        $confidences = $this->getDocumentConfidences($tempTransaction);
        if (!empty($confidences)) {
            $this->saveConfidences($tempTransaction, $confidences);
            $tempTransaction->setMatchConfidence(max($confidences));
            $this->tempTransactionResource->save($tempTransaction);
        }
        return count($confidences);
    }

    /**
     * @param int[]|TempTransaction[] $tempTransactions
     *
     * @return string
     */
    public function matchTransactions(array|TempTransactionCollection $tempTransactions): string
    {
        $foundDocuments = 0;
        $processed = 0;

        if (empty($tempTransactions)) {
            return __('No transactions to match');
        }

        if (is_array($tempTransactions) && !is_object($tempTransactions[0])) {
            $tempTransactions = $this->tempTransactionCollectionFactory->create()
                ->addFieldToFilter('entity_id', ['in' => $tempTransactions]);
        }

        $total = count($tempTransactions);
        $this->progress(0, $total);
        foreach ($tempTransactions as $tempTransaction) {
            try {
                $foundDocuments += $this->processTempTransaction($tempTransaction);
                $processed++;
            } catch (Exception $e) {
                $this->logger->error($e);
                $this->logger->error(_('Error while matching TempTransaction: ') . $e->getMessage());
            }
            $this->progress($processed, $total);
        }
        return __('Found %1 documents for %2 transactions', $foundDocuments, count($tempTransactions));
    }

    /**
     * @return string
     */
    public function matchNewTransactions(): string
    {
        $alreadyMatched = $this->matchConfidenceCollectionFactory->create()
            ->distinct(true)
            ->getColumnValues('temp_transaction_id');

        $tempTransactions = $this->tempTransactionCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['nin' => $alreadyMatched]);

        return $this->matchTransactions($tempTransactions);
    }

    /**
     * @return string
     * @throws AlreadyExistsException
     * @throws CouldNotDeleteExceptionAlias
     */
    public function matchAllTransactions(): string
    {
        $this->matchConfidenceRepository->deleteAll();
        $tempTransactions = $this->tempTransactionCollectionFactory->create();
        foreach ($tempTransactions as $tempTransaction) {
            $tempTransaction->setMatchConfidence(null);
            $tempTransaction->setDocumentCount(0);
            $this->tempTransactionResource->save($tempTransaction);
        }

        return $this->matchTransactions($tempTransactions);
    }

    /**
     * @param TempTransaction $tempTransaction
     * @param array           $documentIds
     *
     * @return void
     * @throws CouldNotSaveException
     */
    private function saveConfidences(TempTransaction $tempTransaction, array $documentIds)
    {
        foreach ($documentIds as $id => $confidence) {
            $matchObject = $this->matchConfidenceFactory->create()
                ->setDocumentId($id)
                ->setTempTransactionId($tempTransaction->getId())
                ->setConfidence($confidence);
            $matchObject->setHasDataChanges(true);
            $this->matchConfidenceRepository->save($matchObject);
        }
    }

    /**
     * @param TempTransaction $tempTransaction
     *
     * @return void
     * @throws CouldNotDeleteExceptionAlias
     */
    private function deleteConfidences(TempTransaction $tempTransaction)
    {
        $existingItems = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId());

        foreach ($existingItems as $existingItem) {
            $this->matchConfidenceRepository->delete($existingItem);
        }
    }
}
