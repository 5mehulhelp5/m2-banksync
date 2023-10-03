<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Model\Dunning;
use Ibertrand\BankSync\Model\DunningRepository;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\Collection as DunningCollection;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory as DunningCollectionFactory;
use Ibertrand\BankSync\Model\Pdf\Dunning as PdfDunning;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Ui\Component\MassAction\Filter;

class MassPrint extends Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_dunnings';

    protected string $redirectUrl = 'banksync/dunning/index';
    protected FileFactory $fileFactory;
    protected DateTime $dateTime;
    protected Filter $filter;
    protected DunningCollectionFactory $collectionFactory;
    protected PdfDunning $pdfDunning;
    protected DunningRepository $dunningRepository;

    /**
     * @param Context                  $context
     * @param DateTime                 $dateTime
     * @param FileFactory              $fileFactory
     * @param Filter                   $filter
     * @param DunningCollectionFactory $collectionFactory
     * @param PdfDunning               $pdfInvoice
     * @param DunningRepository        $dunningRepository
     */
    public function __construct(
        Context                  $context,
        DateTime                 $dateTime,
        FileFactory              $fileFactory,
        Filter                   $filter,
        DunningCollectionFactory $collectionFactory,
        PdfDunning               $pdfInvoice,
        DunningRepository        $dunningRepository,
    )
    {
        $this->fileFactory = $fileFactory;
        $this->dateTime = $dateTime;
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->pdfDunning = $pdfInvoice;
        $this->dunningRepository = $dunningRepository;
        parent::__construct($context);
    }

    /**
     * @return DunningCollection
     */
    protected function getCollection(): DunningCollection
    {
        return $this->collectionFactory->create();
    }

    /**
     * @throws Zend_Pdf_Exception
     * @throws LocalizedException
     */
    protected function getPdfContents($collection): string
    {
        return $this->pdfDunning->getPdf($collection->getItems())->render();
    }

    /**
     * @return string
     */
    protected function getFilename(): string
    {
        return __('Dunnings') . ' ' . date('d.m.Y') . '.pdf';
    }

    /**
     * Save collection items to pdf invoices
     *
     * @param AbstractCollection $collection
     *
     * @return ResponseInterface
     * @throws Exception
     */
    public function massAction(AbstractCollection $collection): ResponseInterface
    {
        $fileContent = ['type' => 'string', 'value' => $this->getPdfContents($collection), 'rm' => true];
        foreach ($collection as $dunning) {
            /** @var Dunning $dunning */
            $dunning->setSentAt(date('Y-m-d H:i:s'));
            $this->dunningRepository->save($dunning);
        }
        return $this->fileFactory->create(
            $this->getFilename(),
            $fileContent,
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
    }

    /**
     * Execute action
     *
     * @return Redirect|ResponseInterface
     * @throws Exception
     */
    public function execute()
    {
        try {
            /** @var AbstractCollection $collection */
            $collection = $this->filter->getCollection($this->getCollection());
            return $this->massAction($collection);
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath($this->redirectUrl);
        }
    }
}
