<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Helper\Data as Helper;
use Ibertrand\BankSync\Lib\NamedCsv;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction as TempTransactionResource;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory as TempTransactionCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory as TransactionCollectionFactory;
use Ibertrand\BankSync\Model\TempTransactionFactory;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Ibertrand\BankSync\Service\Matcher;
use Magento\Backend\App\Action;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class ImportFile extends Action
{
    private NamedCsv $csvProcessor;
    private TempTransactionFactory $tempTransactionFactory;
    private TempTransactionResource $tempTransactionResource;
    private LoggerInterface $logger;
    private ScopeConfigInterface $scopeConfig;
    private Helper $helper;
    private Matcher $matcher;
    private TempTransactionRepository $tempTransactionRepository;
    private TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    private TransactionCollectionFactory $transactionCollectionFactory;

    public function __construct(
        Action\Context                   $context,
        NamedCsv                         $csvProcessor,
        TempTransactionFactory           $tempTransactionFactory,
        TempTransactionResource          $tempTransactionResource,
        TempTransactionRepository        $tempTransactionRepository,
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TransactionCollectionFactory     $transactionCollectionFactory,
        LoggerInterface                  $logger,
        ScopeConfigInterface             $scopeConfig,
        Helper                           $helper,
        Matcher                          $matcher,
    ) {
        parent::__construct($context);
        $this->csvProcessor = $csvProcessor;
        $this->tempTransactionFactory = $tempTransactionFactory;
        $this->tempTransactionResource = $tempTransactionResource;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->transactionCollectionFactory = $transactionCollectionFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->helper = $helper;
        $this->matcher = $matcher;
    }

    /**
     * @return string[]
     */
    private function getColumnMap(): array
    {
        $fields = ['transaction_date', 'payer_name', 'purpose', 'amount'];
        $configPrefix = 'banksync/csv_settings/fields/';
        $columnMap = [];
        foreach ($fields as $field) {
            $columnMap[$field] = $this->scopeConfig->getValue($configPrefix . $field);
        }
        return $columnMap;
    }

    /**
     * @param string $file
     *
     * @return array
     * @throws Exception
     */
    private function readData(string $file): array
    {
        return $this->csvProcessor
            ->setDelimiter($this->scopeConfig->getValue('banksync/csv_settings/general/delimiter'))
            ->setEnclosure($this->scopeConfig->getValue('banksync/csv_settings/general/enclosure'))
            ->getNamedData($file);
    }

    /**
     * @param string $value
     *
     * @return float
     * @throws LocalizedException
     */
    private function parseFloat(string $value): float
    {
        $thousand = trim($this->scopeConfig->getValue('banksync/csv_settings/general/thousand_separator') ?? "");
        $decimal = trim($this->scopeConfig->getValue('banksync/csv_settings/general/decimal_separator') ?? "");
        if ($thousand === $decimal) {
            throw new LocalizedException(__('Thousand separator and decimal separator must be different.'));
        }
        if (!empty($thousand)) {
            $value = str_replace($thousand, '', $value);
        }
        if (!empty($decimal)) {
            $value = str_replace($decimal, '.', $value);
        }
        return (float)$value;
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

            $colMap = $this->getColumnMap();
            $csvRows = $this->readData($csvFilePath);

            if ($this->getRequest()->getParam('delete_old')) {
                $this->tempTransactionRepository->deleteAll();
            }

            $newTransactions = [];
            foreach ($csvRows as $csvRow) {
                $data = [
                    'payer_name' => $csvRow[$colMap['payer_name']] ?? "",
                    'purpose' => $csvRow[$colMap['purpose']] ?? "",
                    'amount' => $this->parseFloat($csvRow[$colMap['amount']] ?? ""),
                    'transaction_date' => date('Y-m-d', strtotime($csvRow[$colMap['transaction_date']] ?? "")),
                    'dirty' => 1,
                ];
                $transaction = $this->tempTransactionFactory->create(['data' => $data]);
                $transaction->setHasDataChanges(true);
                $transaction->setHash($this->helper->calculateHash($transaction));
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
            if (!$this->helper->isAsyncMatching()) {
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

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_import');
    }
}
