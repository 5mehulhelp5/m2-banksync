<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Model\DunningRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class Save extends Action
{

    private DunningRepository $dunningRepository;

    public function __construct(
        Context           $context,
        DunningRepository $dunningRepository,
    ) {
        parent::__construct($context);
        $this->dunningRepository = $dunningRepository;
    }

    /**
     * @return ResponseInterface|Redirect
     * @throws Exception
     */
    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');

        $dunningId = $this->getRequest()->getParam('entity_id');
        if (!$dunningId) {
            $this->messageManager->addErrorMessage(__('The dunning could not be saved.'));
            return $redirect;
        }

        $comment = $this->getRequest()->getParam('comment');
        if (is_string($comment) && empty(trim($comment))) {
            $comment = null;
        }

        $dunning = $this->dunningRepository->getById($dunningId);
        $dunning->setComment($comment);
        $this->dunningRepository->save($dunning);

        $this->messageManager->addSuccessMessage(__('The dunning was saved successfully.'));

        return $redirect;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_dunnings');
    }
}
