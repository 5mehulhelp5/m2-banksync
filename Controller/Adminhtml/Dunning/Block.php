<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\Dunning;
use Ibertrand\BankSync\Model\DunningRepository;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\InvoiceRepository;

class Block extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_dunnings';
    private InvoiceRepository $invoiceRepository;
    private Logger $logger;
    private CollectionFactory $dunningCollectionFactory;
    private DunningRepository $dunningRepository;

    /**
     * @param Context           $context
     * @param InvoiceRepository $invoiceRepository
     * @param Logger            $logger
     * @param CollectionFactory $dunningCollectionFactory
     * @param DunningRepository $dunningRepository
     */
    public function __construct(
        Context           $context,
        InvoiceRepository $invoiceRepository,
        Logger            $logger,
        CollectionFactory $dunningCollectionFactory,
        DunningRepository $dunningRepository,
    ) {
        parent::__construct($context);
        $this->invoiceRepository = $invoiceRepository;
        $this->logger = $logger;
        $this->dunningCollectionFactory = $dunningCollectionFactory;
        $this->dunningRepository = $dunningRepository;
    }

    /**
     * @param int  $invoiceId
     * @param bool $paidStatus
     * @return void
     * @throws CouldNotSaveException
     */
    private function setDunningPaidStatus(int $invoiceId, bool $paidStatus): void
    {
        $dunnings = $this->dunningCollectionFactory->create()
            ->addFieldToFilter('invoice_id', $invoiceId)
            ->addFieldToFilter('is_paid', $paidStatus ? 0 : 1);

        $paid = $paidStatus ? 1 : 0;
        foreach ($dunnings as $dunning) {
            /** @var Dunning $dunning */
            $dunning->setIsPaid($paid);
            $this->dunningRepository->save($dunning);
        }
    }

    /**
     * @param int  $invoiceId
     * @param bool $setBlocked
     * @return void
     * @throws InputException
     * @throws NoSuchEntityException
     */
    private function blockInvoice(int $invoiceId, bool $setBlocked): void
    {
        $invoice = $this->invoiceRepository->get($invoiceId);
        if ($setBlocked) {
            $this->logger->info('Invoice ' . $invoice->getIncrementId() . ' blocked for dunning');
            $invoice->setBanksyncDunningBlockedAt(date('Y-m-d H:i:s'));
        } else {
            $this->logger->info('Invoice ' . $invoice->getIncrementId() . ' unblocked for dunning');
            $invoice->setBanksyncDunningBlockedAt(null);
        }
        $this->invoiceRepository->save($invoice);

    }

    /**
     * @return Redirect
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function execute()
    {
        $invoiceId = $this->getRequest()->getParam('invoice_id');
        $setBlocked = !empty($this->getRequest()->getParam('set_blocked'));

        $this->blockInvoice($invoiceId, $setBlocked);
        $this->setDunningPaidStatus($invoiceId, $setBlocked);

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('sales/invoice/view', ['invoice_id' => $invoiceId]);

        return $redirect;
    }
}
