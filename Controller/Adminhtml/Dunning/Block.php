<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Ibertrand\BankSync\Logger\Logger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\InvoiceRepository;

class Block extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_dunnings';
    private InvoiceRepository $invoiceRepository;
    private Logger $logger;

    /**
     * @param Context           $context
     * @param InvoiceRepository $invoiceRepository
     * @param Logger            $logger
     */
    public function __construct(Context $context, InvoiceRepository $invoiceRepository, Logger $logger)
    {
        parent::__construct($context);
        $this->invoiceRepository = $invoiceRepository;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     * @throws NoSuchEntityException
     * @throws InputException
     */
    public function execute()
    {
        $invoiceId = $this->getRequest()->getParam('invoice_id');
        $setBlocked = !empty($this->getRequest()->getParam('set_blocked'));

        $invoice = $this->invoiceRepository->get($invoiceId);
        if ($setBlocked) {
            $this->logger->info('Invoice ' . $invoice->getIncrementId() . ' blocked for dunning');
            $invoice->setBanksyncDunningBlockedAt(date('Y-m-d H:i:s'));
        } else {
            $this->logger->info('Invoice ' . $invoice->getIncrementId() . ' unblocked for dunning');
            $invoice->setBanksyncDunningBlockedAt(null);
        }
        $this->invoiceRepository->save($invoice);

        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('sales/invoice/view', ['invoice_id' => $invoiceId]);

        return $redirect;
    }
}
