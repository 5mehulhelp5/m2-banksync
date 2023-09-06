<?php

namespace Ibertrand\BankSync\Service;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Matching;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\MatchConfidence;
use Ibertrand\BankSync\Model\MatchConfidenceRepository;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as TempTransactionResource;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory as TempTransactionCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\Transaction as TransactionResource;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory as TransactionCollectionFactory;
use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Ibertrand\BankSync\Model\Transaction;
use Ibertrand\BankSync\Model\TransactionRepository;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\InvoiceRepository;

class Booker
{
    protected TransactionResource $transactionResource;
    protected TempTransactionResource $tempTransactionResource;
    protected TempTransactionRepository $tempTransactionRepository;
    protected InvoiceRepository $invoiceRepository;
    protected CreditmemoRepository $creditmemoRepository;
    protected TransactionRepository $transactionRepository;
    protected TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    protected TransactionCollectionFactory $transactionCollectionFactory;
    protected MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    protected MatchConfidenceRepository $matchConfidenceRepository;
    protected Config $config;
    protected Matching $matching;
    protected Logger $logger;

    public function __construct(
        TempTransactionResource          $tempTransactionResource,
        TransactionResource              $transactionResource,
        TempTransactionRepository        $tempTransactionRepository,
        TransactionRepository            $transactionRepository,
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TransactionCollectionFactory     $transactionCollectionFactory,
        MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory,
        MatchConfidenceRepository        $matchConfidenceRepository,
        InvoiceRepository                $invoiceRepository,
        CreditmemoRepository             $creditmemoRepository,
        Config                           $config,
        Matching                         $matching,
        Logger                           $logger,
    ) {
        $this->tempTransactionResource = $tempTransactionResource;
        $this->transactionResource = $transactionResource;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->transactionRepository = $transactionRepository;
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->transactionCollectionFactory = $transactionCollectionFactory;
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->matchConfidenceRepository = $matchConfidenceRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->config = $config;
        $this->matching = $matching;
        $this->logger = $logger;
    }

    /**
     * @param Transaction|TempTransaction|Invoice|Creditmemo $object
     * @return InvoiceRepository|CreditmemoRepository
     */
    protected function resolveDocumentRepository(
        Transaction|TempTransaction|Invoice|Creditmemo $object,
    ): InvoiceRepository|CreditmemoRepository {
        return match (true) {
            $object instanceof Invoice => $this->invoiceRepository,
            $object instanceof Creditmemo => $this->creditmemoRepository,
            default => $object->getDocumentType() == 'invoice'
                ? $this->invoiceRepository
                : $this->creditmemoRepository
        };
    }

    /**
     * @param Invoice|Creditmemo $document
     * @param bool               $isBanksynced
     * @return void
     * @throws CouldNotSaveException
     */
    private function saveDocument(Invoice|Creditmemo $document, bool $isBanksynced): void
    {
        /** @noinspection PhpUndefinedMethodInspection */
        $document->setIsBanksynced((int)$isBanksynced)
            ->setHasDataChanges(true);
        $this->resolveDocumentRepository($document)->save($document);
    }

    /**
     * @param TempTransaction|int    $tempTransaction
     * @param Invoice|Creditmemo|int $document
     * @param bool                   $partial
     *
     * @return Transaction
     *
     * @throws CouldNotDeleteException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function book(
        TempTransaction|int    $tempTransaction,
        Invoice|Creditmemo|int $document,
        bool                   $partial = false,
    ): Transaction {
        $db = $this->tempTransactionResource->getConnection();
        $db->beginTransaction();
        try {
            if (is_int($tempTransaction)) {
                $tempTransaction = $this->tempTransactionRepository->getById($tempTransaction);
            }

            if (is_int($document)) {
                $document = $this->resolveDocumentRepository($tempTransaction)->get($document);
            }

            $this->saveDocument($document, true);

            $transaction = $this->transactionResource->fromTempTransaction($tempTransaction)
                ->setDocumentId($document->getId())
                ->setMatchConfidence($this->matching->getMatchConfidence($tempTransaction, $document));
            $transaction->setHasDataChanges(true);

            $tempTransactionConfidences = $this->matchConfidenceCollectionFactory->create()
                ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId());

            if (!$partial) {
                $this->tempTransactionRepository->delete($tempTransaction);
            } else {
                $transaction->setAmount($document->getGrandTotal());
                $transaction->setPartialHash($tempTransaction->getHash());

                $tempTransaction->setAmount($tempTransaction->getAmount() - $document->getGrandTotal());
                $tempTransaction->setDirty(TempTransaction::DIRTY);
                $tempTransaction->setPartialHash($tempTransaction->getHash());
                $tempTransaction->setHasDataChanges(true);
                $this->tempTransactionRepository->save($tempTransaction);

                $tempTransactionConfidences->addFieldToFilter('document_id', $document->getId());
            }

            foreach ($tempTransactionConfidences as $confidence) {
                $this->matchConfidenceRepository->delete($confidence);
            }

            $documentConfidences = $this->matchConfidenceCollectionFactory->create()
                ->addFieldToFilter('document_id', $document->getId());

            // Remove all confidences for this document and recalculate temp transaction confidences if necessary
            foreach ($documentConfidences as $documentConfidence) {
                /** @var MatchConfidence $documentConfidence */
                $documentTempTransactionId = $documentConfidence->getTempTransactionId();
                $documentTempTransaction = $this->tempTransactionRepository->getById($documentTempTransactionId);
                if ($documentTempTransaction->getDocumentType() !== $tempTransaction->getDocumentType()) {
                    continue;
                }

                /** @var MatchConfidence $tempTransactionConfidence */
                $tempTransactionConfidence = $this->matchConfidenceCollectionFactory->create()
                    ->addFieldToFilter('temp_transaction_id', $documentTempTransactionId)
                    ->setOrder('confidence', 'DESC')
                    ->getFirstItem();
                $needsUpdate = $tempTransactionConfidence->getDocumentId() == $document->getId();
                $this->matchConfidenceRepository->delete($documentConfidence);
                if ($needsUpdate) {
                    /** @var MatchConfidence $bestConfidence */
                    $bestConfidence = $this->matchConfidenceCollectionFactory->create()
                        ->addFieldToFilter('temp_transaction_id', $documentTempTransactionId)
                        ->setOrder('confidence', 'DESC')
                        ->getFirstItem();
                    $tempTransaction->setMatchConfidence($bestConfidence->getConfidence());
                    $tempTransaction->setDirty(TempTransaction::DIRTY);
                    $this->tempTransactionRepository->save($tempTransaction);
                }
            }

            $this->transactionRepository->save($transaction);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return $transaction;
    }

    /**
     * @param Transaction|int $transaction
     *
     * @return void
     * @throws AlreadyExistsException
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     * @throws InputException
     * @throws CouldNotSaveException
     */
    public function unbook(Transaction|int $transaction): void
    {
        $db = $this->transactionResource->getConnection();
        $db->beginTransaction();
        try {
            if (is_int($transaction)) {
                $transaction = $this->transactionRepository->getById($transaction);
            }

            $document = $this->resolveDocumentRepository($transaction)->get($transaction->getDocumentId());
            $isStillPaid = $this->transactionCollectionFactory->create()
                    ->addFieldToFilter('document_id', $document->getId())
                    ->addFieldToFilter('document_type', $transaction->getDocumentType())
                    ->addFieldToFilter('entity_id', ['neq' => $transaction->getId()])
                    ->getSize() > 0;
            $this->saveDocument($document, $isStillPaid);

            $tempTransaction = null;
            if ($transaction->getPartialHash()) {
                $tempTransactionCollection = $this->tempTransactionCollectionFactory->create()
                    ->addFieldToFilter('partial_hash', $transaction->getPartialHash());


                if ($tempTransactionCollection->getSize() > 0) {
                    /** @var TempTransaction $tempTransaction */
                    $tempTransaction = $tempTransactionCollection->getFirstItem();
                    $tempTransaction->setAmount($tempTransaction->getAmount() + $transaction->getAmount());
                    $tempTransaction->setDirty(TempTransaction::DIRTY);

                    $transactionCollection = $this->transactionCollectionFactory->create()
                        ->addFieldToFilter('partial_hash', $transaction->getPartialHash())
                        ->addFieldToFilter('entity_id', ['neq' => $transaction->getId()]);

                    if ($transactionCollection->getSize() == 0) {
                        $tempTransaction->setPartialHash(null);
                    }
                }
            }

            if (!$tempTransaction) {
                $tempTransaction = $this->tempTransactionResource->fromTransaction($transaction);
                $tempTransaction->setDirty(TempTransaction::DIRTY);
            }
            $tempTransaction->setHasDataChanges(true);

            $this->tempTransactionResource->save($tempTransaction);
            $this->transactionRepository->delete($transaction);

            // With unbooking, the document is now free to be matched again, which can potentially affect other
            // temp transactions. Therefore, we need to recalculate the match confidence for all temp transactions.
            $allTempTransactions = $this->tempTransactionCollectionFactory->create();
            foreach ($allTempTransactions as $tempTransaction) {
                $tempTransaction->setIsDirty(TempTransaction::DIRTY);
                $tempTransaction->setHasDataChanges(true);
                $this->tempTransactionRepository->save($tempTransaction);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * @param int[]|null $ids
     *
     * @return int[][]
     * @throws CouldNotSaveException
     */
    public function autoBook(array $ids = null, $minThreshold = null): array
    {
        $result = [
            'success' => [],
            'error' => [],
        ];
        if ($minThreshold === null) {
            $minThreshold = $this->config->getAcceptConfidenceThreshold();
        }

        $absoluteThreshold = $this->config->getAbsoluteConfidenceThreshold();
        $acceptanceThreshold = $this->config->getAcceptConfidenceThreshold();

        $tempTransactions = $this->tempTransactionCollectionFactory->create()
            ->addFieldToFilter('match_confidence', ['gteq' => $minThreshold]);

        if (is_array($ids)) {
            $tempTransactions->addFieldToFilter('entity_id', ['in' => $ids]);
        }

        $allRelatedBookedTransaction = $this->transactionCollectionFactory->create()
            ->addFieldToFilter('document_id', ['in' => $tempTransactions->getColumnValues('document_id')])
            ->getItems();

        foreach ($tempTransactions as $tempTransaction) {
            /** @var TempTransaction $tempTransaction */
            /** @var MatchConfidence[] $allMatches */
            $allMatches = $this->matchConfidenceCollectionFactory->create()
                ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId())
                ->addFieldToFilter('confidence', ['gteq' => $minThreshold])
                ->getItems();

            $acceptMatches = array_filter($allMatches, fn ($m) => $m->getConfidence() >= $acceptanceThreshold);
            $absoluteMatches = array_filter($allMatches, fn ($m) => $m->getConfidence() >= $absoluteThreshold);

            if (count($allMatches) !== 1 && count($acceptMatches) !== 1 && count($absoluteMatches) !== 1) {
                $this->logger->error("Transaction {$tempTransaction->getId()} has " . count($allMatches) . " matches");
                continue;
            }

            usort($allMatches, fn ($a, $b) => $b->getConfidence() <=> $a->getConfidence());

            $documentId = $allMatches[0]->getDocumentId();
            foreach ($allRelatedBookedTransaction as $bookedTransaction) {
                /** @var Transaction $bookedTransaction */
                if ($bookedTransaction->getDocumentId() == $documentId && $bookedTransaction->getDocumentType() == $tempTransaction->getDocumentType()) {
                    $tempTransaction->setDirty(TempTransaction::DIRTY);
                    $tempTransaction->setMatchConfidence(null);
                    $this->tempTransactionRepository->save($tempTransaction);
                    $result['error'][] = $tempTransaction->getId();
                    continue 2;
                }
            }

            try {
                $document = $this->resolveDocumentRepository($tempTransaction)->get($documentId);

                $confidence = $this->matching->getMatchConfidence($tempTransaction, $document);
                if ($confidence < $minThreshold) {
                    echo "Transaction {$tempTransaction->getId()} has low confidence: $confidence\n";
                    continue;
                }

                $allRelatedBookedTransaction[] = $this->book($tempTransaction, $document);

                $result['success'][] = $tempTransaction->getId();
            } catch (Exception $e) {
                $result['error'][] = $tempTransaction->getId();
                $this->logger->error($e);
                continue;
            }
        }
        return $result;
    }
}
