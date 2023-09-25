<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\DunningRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class SendMail extends Action
{

    private DunningRepository $dunningRepository;
    private Logger $logger;

    /**
     * @param Context           $context
     * @param DunningRepository $dunningRepository
     * @param Logger            $logger
     */
    public function __construct(Context $context, DunningRepository $dunningRepository, Logger $logger)
    {
        parent::__construct($context);
        $this->dunningRepository = $dunningRepository;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $dunningId = $this->getRequest()->getParam('id');
        $dunning = $this->dunningRepository->getById($dunningId);
        try {
            if ($dunning->sendMail()) {
                $this->messageManager->addSuccessMessage(__('The mail has been sent.'));
            } else {
                $this->messageManager->addErrorMessage(__('There was an error sending the mail.'));
            }
        } catch (Exception $e) {
            $this->logger->error($e->getMessage() . "\n" . $e->getTraceAsString());
            $this->messageManager->addErrorMessage(_('There was an error sending the mail: ') . $e->getMessage());
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('*/*/index');

        return $redirect;
    }

    /**
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_dunnings');
    }
}
