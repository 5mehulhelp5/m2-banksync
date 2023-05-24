<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;

class Details extends Action
{
    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->prepend(__('New Transaction Details'));
        return $resultPage;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(
            'Ibertrand_BankSync::sub_menu_temp_transactions'
        );
    }
}
