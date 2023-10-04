<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Service\Booker;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Archive extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::book';

    protected Booker $booker;
    protected Logger $logger;

    public function __construct(
        Context $context,
        Booker  $booker,
        Logger  $logger,
    ) {
        $this->booker = $booker;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute()
    {
        $transactionId = $this->getRequest()->getParam('id');

        try {
            $this->booker->archive($transactionId);
            $this->messageManager->addSuccessMessage(__('Transaction archived'));
        } catch (Exception $e) {
            $this->logger->error($e);
            $this->messageManager->addErrorMessage(__('Failed to archive the transaction'));
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $redirect->setPath('*/*/index');

        return $redirect;
    }
}
