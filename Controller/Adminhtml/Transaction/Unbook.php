<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Transaction;

use Exception;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory;
use Ibertrand\BankSync\Service\Booker;
use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Psr\Log\LoggerInterface;

class Unbook extends Action
{
    private LoggerInterface $logger;
    private Booker $booker;
    private Filter $filter;
    private CollectionFactory $collectionFactory;

    public function __construct(
        Action\Context    $context,
        Filter            $filter,
        CollectionFactory $collectionFactory,
        Booker            $booker,
        LoggerInterface   $logger,
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->booker = $booker;
        $this->logger = $logger;
    }

    /**
     * @return ResponseInterface|Redirect|ResultInterface
     * @throws LocalizedException
     */
    public function execute()
    {
        if ($id = $this->getRequest()->getParam('id')) {
            $transactions = $this->collectionFactory->create()->addFieldToFilter('entity_id', $id);
        } else {
            $transactions = $this->filter->getCollection($this->collectionFactory->create());
        }

        $success = 0;
        $fail = 0;
        foreach ($transactions as $transaction) {
            try {
                $this->booker->unbook($transaction);
                $success++;
            } catch (Exception $e) {
                $this->logger->error($e);
                $fail++;
            }
        }
        if ($success > 0 && $fail == 0) {
            $this->messageManager->addSuccessMessage(
                __('%1 transaction(s) have been unbooked successfully.', $success)
            );
        } elseif ($success == 0 && $fail > 0) {
            $this->messageManager->addWarningMessage(
                __('%1 transaction(s) have been successfully unbooked, %2 failed.', $success, $fail)
            );
        } else {
            $this->messageManager->addErrorMessage(__('%1 transaction(s) could not be unbooked.', $fail));
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('*/' . ($fail > 0 ? 'transaction' : 'temptransaction') . '/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::book');
    }
}
