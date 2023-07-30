<?php

namespace Ibertrand\BankSync\Service;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Matching;
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
use Magento\Reports\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection as CreditmemoCollection;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Ibertrand\BankSync\Logger\Logger;

class Matcher
{
    protected TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    protected TempTransactionResource $tempTransactionResource;
    protected Logger $logger;
    protected InvoiceCollectionFactory $invoiceCollectionFactory;
    protected CreditmemoCollectionFactory $creditmemoCollectionFactory;
    protected MatchConfidenceFactory $matchConfidenceFactory;
    protected MatchConfidenceRepository $matchConfidenceRepository;
    protected MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    /**
     * @var callable
     */
    protected $progressCallBack;
    protected CustomerCollectionFactory $customerCollectionFactory;
    protected OrderCollectionFactory $orderCollectionFactory;
    protected Config $config;
    protected Matching $matching;

    public function __construct(
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TempTransactionResource          $transactionResource,
        Logger                   $logger,
        InvoiceCollectionFactory         $invoiceCollectionFactory,
        CreditmemoCollectionFactory      $creditmemoCollectionFactory,
        MatchConfidenceFactory           $matchConfidenceFactory,
        MatchConfidenceRepository        $matchConfidenceRepository,
        MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory,
        CustomerCollectionFactory        $customerCollectionFactory,
        OrderCollectionFactory           $orderCollectionFactory,
        Config                           $config,
        Matching                         $matching,
    ) {
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->tempTransactionResource = $transactionResource;
        $this->logger = $logger;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->matchConfidenceFactory = $matchConfidenceFactory;
        $this->matchConfidenceRepository = $matchConfidenceRepository;
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->config = $config;
        $this->matching = $matching;
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
    protected function progress(int $current, int $total): void
    {
        if ($this->progressCallBack) {
            call_user_func($this->progressCallBack, $current, $total);
        }
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return InvoiceCollection|CreditmemoCollection
     * @throws LocalizedException
     */
    protected function getBaseDocumentCollection(TempTransaction $tempTransaction): InvoiceCollection|CreditmemoCollection
    {
        $collection = $tempTransaction->getAmount() >= 0
            ? $this->invoiceCollectionFactory->create()
            : $this->creditmemoCollectionFactory->create();

        $collection
            ->addFieldToFilter('state', ['neq' => 'canceled']);

        $condition = $collection->getConnection()->quoteInto(
            'tt.document_id = main_table.entity_id and tt.document_type = ?',
            $tempTransaction->getDocumentType(),
        );
        $collection->getSelect()->joinLeft(['tt' => 'banksync_transaction'], $condition, '');
        $collection->getSelect()->where('tt.document_id is null');

        $paymentMethods = $this->config->getPaymentMethods();
        if (!empty($paymentMethods)) {
            $collection->join(
                ['payment' => 'sales_order_payment'],
                'main_table.order_id = payment.parent_id',
                []
            )->addFieldToFilter('payment.method', ['in' => $paymentMethods]);
        }

        return $collection;
    }

    /**
     * @param ?string $purpose
     * @return array
     */
    public function extractDocumentNumbersFromPurpose(?string $purpose): array
    {
        $pattern = $this->config->getNrFilterPattern('document');
        if (empty($purpose) || empty($pattern)) {
            return [];
        }
        if (!preg_match_all($pattern, $purpose, $matches)) {
            return [];
        }

        if (empty($matches[0])) {
            return [];
        }
        return $matches[0];
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return array
     * @throws LocalizedException
     */
    public function getDocumentsViaDocumentNumbers(TempTransaction $tempTransaction): array
    {
        $numbers = $this->extractDocumentNumbersFromPurpose($tempTransaction->getPurpose());
        if (empty($numbers)) {
            return [];
        }

        $collection = $this->getBaseDocumentCollection($tempTransaction);
        $collection->addFieldToFilter('increment_id', ['in' => $numbers]);

        return $collection->getItems();
    }

    /**
     * @param ?string $purpose
     * @return array
     */
    public function extractOrderNumbersFromPurpose(?string $purpose): array
    {
        $pattern = $this->config->getNrFilterPattern('order');
        if (empty($purpose) || empty($pattern)) {
            return [];
        }
        if (!preg_match_all($pattern, $purpose, $matches)) {
            return [];
        }
        if (empty($matches[0])) {
            return [];
        }
        return $matches[0];
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return array
     * @throws LocalizedException
     */
    protected function getDocumentsViaOrderNumbers(TempTransaction $tempTransaction): array
    {
        $numbers = $this->extractOrderNumbersFromPurpose($tempTransaction->getPurpose());
        if (empty($numbers)) {
            return [];
        }

        $orderIds = $this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', ['in' => $numbers])
            ->getAllIds();

        if (empty($orderIds)) {
            return [];
        }

        $collection = $this->getBaseDocumentCollection($tempTransaction);
        $collection->addFieldToFilter('order_id', ['in' => $orderIds]);

        return $collection->getItems();
    }

    /**
     * @param ?string $purpose
     * @return array
     */
    public function extractCustomerNumbersFromPurpose(?string $purpose): array
    {
        $pattern = $this->config->getNrFilterPattern('customer');
        if (empty($purpose) || empty($pattern)) {
            return [];
        }
        if (!preg_match_all($pattern, $purpose, $matches)) {
            return [];
        }
        if (empty($matches[0])) {
            return [];
        }
        return $matches[0];
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return array
     * @throws LocalizedException
     */
    protected function getDocumentsViaCustomer(TempTransaction $tempTransaction): array
    {
        $numbers = $this->extractCustomerNumbersFromPurpose($tempTransaction->getPurpose());
        if (empty($numbers)) {
            return [];
        }

        $customerIds = $this->customerCollectionFactory->create()
            ->addFieldToFilter('increment_id', ['in' => $numbers])
            ->getAllIds();

        if (empty($customerIds)) {
            return [];
        }

        $orderIds = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', ['in' => $customerIds])
            ->getAllIds();

        if (empty($orderIds)) {
            return [];
        }

        $collection = $this->getBaseDocumentCollection($tempTransaction);
        $collection->addFieldToFilter('order_id', ['in' => $orderIds]);

        return $collection->getItems();
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return array
     * @throws LocalizedException
     */
    protected function getDocumentsViaAmount(TempTransaction $tempTransaction): array
    {
        $amount = abs($tempTransaction->getAmount());
        $amountThreshold = $this->config->getAmountThreshold();
        $latestDate = date(
            'Y-m-d H:i:s',
            strtotime($tempTransaction->getTransactionDate()) + $this->config->getDateThreshold() * 86400
        );

        $collection = $this->getBaseDocumentCollection($tempTransaction)
            ->addFieldToFilter('main_table.created_at', ['gteq' => $this->config->getStartDate()])
            ->addFieldToFilter('main_table.created_at', ['lteq' => $latestDate])
            ->addFieldToFilter('grand_total', ['gteq' => $amount - $amountThreshold])
            ->addFieldToFilter('grand_total', ['lteq' => $amount + $amountThreshold]);

        return $collection->getItems();
    }

    /**
     * @param TempTransaction $tempTransaction
     *
     * @return int[]
     * @throws LocalizedException
     */
    protected function getDocumentConfidences(TempTransaction $tempTransaction): array
    {
        $documents = $this->getDocuments($tempTransaction);

        $minConfidence = $this->config->getMinConfidenceThreshold();
        $confidences = [];
        foreach ($documents as $document) {
            /** @var Invoice|Creditmemo $document */
            $confidence = $this->matching->getMatchConfidence($tempTransaction, $document);
            if ($confidence >= $minConfidence) {
                $confidences[$document->getId()] = $confidence;
            }
        }
        return $confidences;
    }

    /**
     * @param TempTransaction $tempTransaction
     * @return array
     * @throws LocalizedException
     */
    public function getDocuments(TempTransaction $tempTransaction): array
    {
        return array_merge(
            $this->getDocumentsViaAmount($tempTransaction),
            $this->getDocumentsViaDocumentNumbers($tempTransaction),
            $this->getDocumentsViaOrderNumbers($tempTransaction),
            $this->getDocumentsViaCustomer($tempTransaction),
        );
    }

    /**
     * @param TempTransaction $tempTransaction
     *
     * @return int
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws CouldNotDeleteExceptionAlias
     */
    protected function processTempTransaction(TempTransaction $tempTransaction): int
    {
        $this->deleteConfidences($tempTransaction);
        $confidences = $this->getDocumentConfidences($tempTransaction);
        if (!empty($confidences)) {
            $this->saveConfidences($tempTransaction, $confidences);
            $tempTransaction->setMatchConfidence(max($confidences));
            $tempTransaction->setDirty(TempTransaction::NOT_DIRTY);
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

        if (count($tempTransactions) == 0) {
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
        $tempTransactions = $this->tempTransactionCollectionFactory->create()
            ->addFieldToFilter('dirty', 1);

        return $this->matchTransactions($tempTransactions);
    }

    /**
     * @return string
     * @throws AlreadyExistsException
     * @throws CouldNotDeleteExceptionAlias
     */
    public function matchAllTransactions(): string
    {
        $this->logger->info('Matching all transactions');

        $this->matchConfidenceRepository->deleteAll();
        $tempTransactions = $this->tempTransactionCollectionFactory->create();
        foreach ($tempTransactions as $tempTransaction) {
            $tempTransaction->setMatchConfidence(null);
            $tempTransaction->setDocumentCount(0);
            $this->tempTransactionResource->save($tempTransaction);
        }
        $this->deleteAllConfidences();

        return $this->matchTransactions($tempTransactions);
    }

    /**
     * @param TempTransaction $tempTransaction
     * @param array           $documentIds
     *
     * @return void
     * @throws CouldNotSaveException
     */
    protected function saveConfidences(TempTransaction $tempTransaction, array $documentIds): void
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
    protected function deleteConfidences(TempTransaction $tempTransaction): void
    {
        $existingItems = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId());

        foreach ($existingItems as $existingItem) {
            $this->matchConfidenceRepository->delete($existingItem);
        }
    }

    /**
     * @return void
     * @throws CouldNotDeleteExceptionAlias
     */
    protected function deleteAllConfidences(): void
    {
        $existingItems = $this->matchConfidenceCollectionFactory->create();

        foreach ($existingItems as $existingItem) {
            $this->matchConfidenceRepository->delete($existingItem);
        }
    }
}
