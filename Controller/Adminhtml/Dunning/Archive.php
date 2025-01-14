<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Model\DunningRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class Archive extends Action
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

        $dunningId = $this->getRequest()->getParam('id');
        if (!$dunningId) {
            return $redirect;
        }
        $dunning = $this->dunningRepository->getById($dunningId);

        if ($dunning->getArchivedAt()) {
            $this->messageManager->addErrorMessage(__('The dunning was already archived.'));
            return $redirect;
        }

        $dunning->setArchivedAt(date('Y-m-d H:i:s'));
        $this->dunningRepository->save($dunning);

        $this->messageManager->addSuccessMessage(__('The dunning was archived.'));

        return $redirect;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_dunnings');
    }
}
