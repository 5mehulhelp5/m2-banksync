<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\CsvFormat;

use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_csv_format';

    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->getConfig()->getTitle()->prepend(__('Csv Formats'));

        return $resultPage;
    }

}
