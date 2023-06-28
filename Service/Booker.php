<?php

namespace Ibertrand\BankSync\Service;

use Ibertrand\BankSync\Helper\Data as Helper;
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
use Psr\Log\LoggerInterface;

class Booker
{
    private TransactionResource $transactionResource;
    private TempTransactionResource $tempTransactionResource;
    private TempTransactionRepository $tempTransactionRepository;
    private InvoiceRepository $invoiceRepository;
    private CreditmemoRepository $creditmemoRepository;
    private Helper $helper;
    private TransactionRepository $transactionRepository;
    private TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    private TransactionCollectionFactory $transactionCollectionFactory;
    private MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    private MatchConfidenceRepository $matchConfidenceRepository;
    private LoggerInterface $logger;

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
        Helper                           $helper,
        LoggerInterface                  $logger,
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
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * @param TempTransaction|int    $tempTransaction
     * @param Invoice|Creditmemo|int $document
     * @param bool                   $partial
     *
     * @return void
     *
     * @throws AlreadyExistsException
     * @throws CouldNotDeleteException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     */
    public function book(TempTransaction|int $tempTransaction, Invoice|Creditmemo|int $document, $partial = false): void
    {
        if (is_int($tempTransaction)) {
            $tempTransaction = $this->tempTransactionRepository->getById($tempTransaction);
        }
        if (is_int($document)) {
            $document = $tempTransaction->getDocumentType() === 'invoice'
                ? $this->invoiceRepository->get($document)
                : $this->creditmemoRepository->get($document);
        }

        $transaction = $this->transactionResource->fromTempTransaction($tempTransaction)
            ->setDocumentId($document->getId())
            ->setMatchConfidence($this->helper->getMatchConfidence($tempTransaction, $document));
        $transaction->setHasDataChanges(true);

        if (!$partial) {
            $this->tempTransactionRepository->delete($tempTransaction);
        } else {
            $transaction->setAmount($document->getGrandTotal());
            $transaction->setPartialHash($tempTransaction->getHash());

            $tempTransaction->setAmount($tempTransaction->getAmount() - $document->getGrandTotal());
            $tempTransaction->setDirty(1);
            $tempTransaction->setPartialHash($tempTransaction->getHash());
            $tempTransaction->setHasDataChanges(true);
            $this->tempTransactionRepository->save($tempTransaction);

            /** @var MatchConfidence $confidence */
            $confidence = $this->matchConfidenceCollectionFactory->create()
                ->addFieldToFilter('temp_transaction_id', $transaction->getId())
                ->addFieldToFilter('document_id', $document->getId())
                ->getFirstItem();
            if ($confidence->getId()) {
                $this->matchConfidenceRepository->delete($confidence);
            }
        }
        $this->transactionRepository->save($transaction);
    }

    /**
     * @param Transaction|int $transaction
     *
     * @return void
     * @throws AlreadyExistsException
     * @throws CouldNotDeleteException
     * @throws NoSuchEntityException
     */
    public function unbook(Transaction|int $transaction): void
    {
        if (is_int($transaction)) {
            $transaction = $this->transactionRepository->getById($transaction);
        }

        $tempTransaction = null;
        if ($transaction->getPartialHash()) {
            $tempTransactionCollection = $this->tempTransactionCollectionFactory->create()
                ->addFieldToFilter('partial_hash', $transaction->getPartialHash());


            if ($tempTransactionCollection->getSize() > 0) {
                /** @var TempTransaction $tempTransaction */
                $tempTransaction = $tempTransactionCollection->getFirstItem();
                $tempTransaction->setAmount($tempTransaction->getAmount() + $transaction->getAmount());
                $tempTransaction->setDirty(1);

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
        }
        $tempTransaction->setHasDataChanges(true);

        $this->tempTransactionResource->save($tempTransaction);
        $this->transactionRepository->delete($transaction);
    }

    /**
     * @param int[]|null $ids
     *
     * @return int[][]
     */
    public function autoBook(array $ids = null, $threshold = null): array
    {
        $result = [
            'success' => [],
            'error' => [],
        ];
        if ($threshold === null) {
            $threshold = $this->helper->getAcceptConfidenceThreshold();
        }

        $absoluteThreshold = $this->helper->getAbsoluteConfidenceThreshold();

        $tempTransactions = $this->tempTransactionCollectionFactory->create()
            ->addFieldToFilter('match_confidence', ['gteq' => $threshold]);

        if (is_array($ids)) {
            $tempTransactions->addFieldToFilter('entity_id', ['in' => $ids]);
        }

        foreach ($tempTransactions as $tempTransaction) {
            /** @var MatchConfidence[] $allMatches */
            $allMatches = $this->matchConfidenceCollectionFactory->create()
                ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId())
                ->addFieldToFilter('confidence', ['gteq' => $threshold])
                ->getItems();


            $absoluteMatches = array_filter($allMatches, fn ($m) => $m->getConfidence() >= $absoluteThreshold);

            if (count($allMatches) !== 1 && count($absoluteMatches) !== 1) {
                $this->logger->error("Transaction {$tempTransaction->getId()} has " . count($allMatches) . " matches");
                continue;
            }

            usort($allMatches, fn ($b, $a) => $b->getConfidence() <=> $a->getConfidence());

            $documentId = $allMatches[0]->getDocumentId();

            try {
                $document = $tempTransaction->getDocumentType() === 'invoice'
                    ? $this->invoiceRepository->get($documentId)
                    : $this->creditmemoRepository->get($documentId);

                $confidence = $this->helper->getMatchConfidence($tempTransaction, $document);
                if ($confidence < $threshold) {
                    echo "Transaction {$tempTransaction->getId()} has low confidence: $confidence\n";
                    continue;
                }

                $this->book($tempTransaction, $document);
                $result['success'][] = $tempTransaction->getId();
            } catch (AlreadyExistsException|CouldNotDeleteException|InputException|NoSuchEntityException $e) {
                $result['error'][] = $tempTransaction->getId();
                $this->logger->error($e);
                continue;
            }
        }
        return $result;
    }
}
