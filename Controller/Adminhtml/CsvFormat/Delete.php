<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\CsvFormat;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\CsvFormatRepository;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;

class Delete extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_csv_format';
    protected Logger $logger;
    private CsvFormatRepository $csvFormatRepository;

    public function __construct(
        Action\Context      $context,
        CsvFormatRepository $csvFormatRepository,
        Logger              $logger,
    ) {
        parent::__construct($context);
        $this->csvFormatRepository = $csvFormatRepository;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $id = $this->getRequest()->getParam('id');

        try {
            $this->csvFormatRepository->deleteById($id);
            $this->messageManager->addSuccessMessage(__('Format deleted.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Format not found.'));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('An error occurred while deleting the format.'));
            $this->logger->error($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
