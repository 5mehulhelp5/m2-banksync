<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Ibertrand\BankSync\Service\Booker;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends Action
{
    protected Logger $logger;
    protected Booker $booker;
    protected TempTransactionRepository $tempTransactionRepository;

    public function __construct(
        Action\Context            $context,
        TempTransactionRepository $tempTransactionRepository,
        Booker                    $booker,
        Logger                    $logger,
    ) {
        parent::__construct($context);
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->booker = $booker;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $comment = $this->getRequest()->getParam('comment');
        $archive = !empty($this->getRequest()->getParam('archive'));

        try {
            $tempTransaction = $this->tempTransactionRepository->getById($this->getRequest()->getParam('entity_id'));
            $tempTransaction->setComment($comment);
            $this->tempTransactionRepository->save($tempTransaction);
            if ($archive) {
                $this->booker->archive($tempTransaction);
                $this->messageManager->addSuccessMessage(__('Transaction archived.'));
            } else {
                $this->messageManager->addSuccessMessage(__('Transaction saved.'));
            }
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Transaction not found.'));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('An error occurred while saving the transaction.'));
            $this->logger->error($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_temp_transactions');
    }
}
