<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Hashes;
use Ibertrand\BankSync\Lib\Csv;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\CsvFormat;
use Ibertrand\BankSync\Model\CsvFormatRepository;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as TempTransactionResource;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory as TempTransactionCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory as TransactionCollectionFactory;
use Ibertrand\BankSync\Model\TempTransactionFactory;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Ibertrand\BankSync\Service\Matcher;
use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

class ImportFile extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_import';
    protected Csv $csvProcessor;
    protected TempTransactionFactory $tempTransactionFactory;
    protected TempTransactionResource $tempTransactionResource;
    protected Logger $logger;
    protected Matcher $matcher;
    protected TempTransactionRepository $tempTransactionRepository;
    protected TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    protected TransactionCollectionFactory $transactionCollectionFactory;
    protected CsvFormatRepository $csvFormatRepository;
    protected Config $config;
    protected Hashes $hashes;

    public function __construct(
        Action\Context                   $context,
        Csv                              $csvProcessor,
        TempTransactionFactory           $tempTransactionFactory,
        TempTransactionResource          $tempTransactionResource,
        TempTransactionRepository        $tempTransactionRepository,
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TransactionCollectionFactory     $transactionCollectionFactory,
        CsvFormatRepository              $csvFormatRepository,
        Logger                           $logger,
        Matcher                          $matcher,
        Config                           $config,
        Hashes                           $hashes,
    ) {
        parent::__construct($context);
        $this->csvProcessor = $csvProcessor;
        $this->tempTransactionFactory = $tempTransactionFactory;
        $this->tempTransactionResource = $tempTransactionResource;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->transactionCollectionFactory = $transactionCollectionFactory;
        $this->csvFormatRepository = $csvFormatRepository;
        $this->logger = $logger;
        $this->matcher = $matcher;
        $this->config = $config;
        $this->hashes = $hashes;
    }

    /**
     * @return CsvFormat
     * @throws LocalizedException
     */
    protected function getCsvFormat(): CsvFormat
    {
        try {
            return $this->csvFormatRepository->getById($this->getRequest()->getParam('csv_format'));
        } catch (Exception $e) {
            $this->logger->error($e);
            throw new LocalizedException(__('CSV format not found.'));
        }
    }

    /**
     * Write 'after' plugins for this method if you need to add more filters
     *
     * @param array $row
     * @return bool
     */
    public function isRowValid(array $row): bool
    {
        if ($row['amount'] < 0 && !$this->config->isSupportCreditmemos()) {
            return false;
        }

        return true;
    }

    /**
     * @return ResponseInterface|Redirect|(Redirect&ResultInterface)|ResultInterface
     */
    public function execute()
    {
        try {
            $csvFile = $this->getRequest()->getParam('import_file')[0];

            $csvFilePath = $csvFile['path'] . '/' . $csvFile['file'];

            if (!is_file($csvFilePath)) {
                throw new LocalizedException(__('File not found.'));
            }

            $csvFormat = $this->getCsvFormat();
            $csvRows = $csvFormat->loadFile($csvFilePath);

            if ($this->getRequest()->getParam('delete_old')) {
                $this->tempTransactionRepository->deleteAll();
            }

            $newTransactions = [];
            foreach ($csvRows as $csvRow) {
                if (!$this->isRowValid($csvRow)) {
                    continue;
                }

                $transaction = $this->tempTransactionFactory->create(['data' => [
                    'payer_name' => $csvRow['payer_name'],
                    'purpose' => $csvRow['purpose'],
                    'amount' => $csvRow['amount'],
                    'transaction_date' => $csvRow['transaction_date'],
                    'csv_source' => $csvFormat->getName(),
                    'dirty' => 1,
                ]]);
                $transaction->setHash($this->hashes->calculateHash($transaction));
                $transaction->setHasDataChanges(true);
                $newTransactions[$transaction->getHash()] = $transaction;
            }

            $hashes = array_keys($newTransactions);

            $existingTempHashes = $this->tempTransactionCollectionFactory->create()
                ->addFieldToFilter('hash', ['in' => $hashes])
                ->getColumnValues('hash');
            $existingBookedHashes = $this->transactionCollectionFactory->create()
                ->addFieldToFilter('hash', ['in' => $hashes])
                ->getColumnValues('hash');

            $newHashes = array_diff($hashes, $existingTempHashes, $existingBookedHashes);
            $newHashes = array_combine($newHashes, $newHashes);

            foreach ($newTransactions as $transaction) {
                if (isset($newHashes[$transaction->getHash()])) {
                    $this->tempTransactionResource->save($transaction);
                }
            }
            unlink($csvFilePath);

            $this->messageManager->addSuccessMessage(__('CSV file has been imported successfully.'));
            if (!$this->config->isAsyncMatching()) {
                try {
                    $matchMsg = $this->matcher->matchNewTransactions();
                    $this->messageManager->addNoticeMessage($matchMsg);
                } catch (Exception $e) {
                    $this->logger->error($e);
                    $this->messageManager->addErrorMessage(
                        __('Error occurred while matching the transactions. Check the logs for more details.')
                    );
                }
            } else {
                $this->messageManager->addNoticeMessage(
                    __('Transactions will be matched in the background. Please check the list in a few minutes.')
                );
            }
        } catch (Exception $e) {
            $this->logger->error($e);
            $this->messageManager->addErrorMessage(
                __('Error occurred while importing the CSV file. Check the logs for more details.')
            );
            return $this->resultRedirectFactory->create()->setPath('*/*/import');
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
