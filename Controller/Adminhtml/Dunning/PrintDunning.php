<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Model\DunningRepository;
use Ibertrand\BankSync\Model\Pdf\Dunning;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;

class PrintDunning extends Action
{

    private DunningRepository $dunningRepository;
    private FileFactory $fileFactory;
    private Dunning $dunningPdf;

    public function __construct(
        Context           $context,
        DunningRepository $dunningRepository,
        FileFactory       $fileFactory,
        Dunning           $dunningPdf,
    ) {
        parent::__construct($context);
        $this->dunningRepository = $dunningRepository;
        $this->fileFactory = $fileFactory;
        $this->dunningPdf = $dunningPdf;
    }

    /**
     * @return ResponseInterface|Redirect
     * @throws Exception
     */
    public function execute()
    {
        $dunningId = $this->getRequest()->getParam('id');
        if ($dunningId) {
            $dunning = $this->dunningRepository->getById($dunningId);
            $dunning->setSentAt(date('Y-m-d H:i:s'));
            $this->dunningRepository->save($dunning);

            $invoice = $dunning->getInvoice();
            $contents = $this->dunningPdf->getPdf([$dunning])->render();
            $fileContent = ['type' => 'string', 'value' => $contents, 'rm' => true];

            return $this->fileFactory->create(
                $dunning->getLabel() . ' ' . $invoice->getIncrementId() . '_print.pdf',
                $fileContent,
                DirectoryList::VAR_DIR,
                'application/pdf'
            );
        }

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('*/*/index');
        return $redirect;
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::sub_menu_dunnings');
    }
}
