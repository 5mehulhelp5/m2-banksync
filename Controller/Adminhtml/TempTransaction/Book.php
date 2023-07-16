<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Service\Booker;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

class Book extends Action
{
    protected Booker $booker;
    protected LoggerInterface $logger;

    public function __construct(
        Context         $context,
        Booker          $booker,
        LoggerInterface $logger,
    ) {
        $this->booker = $booker;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::book');
    }

    public function execute()
    {
        $transactionId = $this->getRequest()->getParam('id');
        $documentId = $this->getRequest()->getParam('document_id');
        $partial = !empty($this->getRequest()->getParam('partial'));

        try {
            $this->booker->book($transactionId, $documentId, $partial);
            $this->messageManager->addSuccessMessage(__('Transaction booked'));
        } catch (Exception $e) {
            $this->logger->error($e);
            $this->messageManager->addErrorMessage(__('Failed to book the transaction'));
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if ($partial) {
            $redirect->setPath('*/*/details', ['id' => $transactionId]);
        } else {
            $redirect->setPath('*/*/index');
        }

        return $redirect;
    }
}
