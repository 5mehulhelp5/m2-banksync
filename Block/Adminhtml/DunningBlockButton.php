<?php

namespace Ibertrand\BankSync\Block\Adminhtml;

use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\EntityInterface;
use Magento\Sales\Model\Order\Invoice;

class DunningBlockButton extends Container
{
    /**
     * Core registry
     *
     * @var Registry
     */
    protected Registry $coreRegistry;

    /**
     * @param Context  $context
     * @param Registry $registry
     * @param array    $data
     */
    public function __construct(
        Context  $context,
        Registry $registry,
        array    $data = [],
    ) {
        $this->coreRegistry = $registry;
        parent::__construct($context, $data);
    }

    protected function _construct()
    {
        $this->addButton(
            'banksync_block_dunning_button',
            [
                'label' => $this->getLabel(),
                'class' => 'dunning_block',
                'confirm' => __('Are you sure you want to do this?'),
                'on_click' => "deleteConfirm( '" . __('Sure?') . "', '" . $this->getTargetUrl() . "')",
            ],
            sortOrder: -5
        );

        parent::_construct();
    }

    /**
     * @return string
     */
    protected function getLabel(): string
    {
        return $this->invoiceIsBlocked()
            ? __('Remove dunning block')
            : __('Dunning block');
    }

    /**
     * @return bool
     */
    protected function invoiceIsBlocked(): bool
    {
        return !empty($this->getInvoice()->getBanksyncDunningBlockedAt());
    }

    /**
     * @return Invoice
     */
    protected function getInvoice(): EntityInterface
    {
        return $this->coreRegistry->registry('current_invoice');
    }

    /**
     * @return string
     */
    protected function getTargetUrl(): string
    {
        $setBlocked = $this->invoiceIsBlocked() ? 0 : 1;
        return $this->getUrl(
            "banksync/dunning/block",
            ['invoice_id' => $this->getInvoiceId(), 'set_blocked' => $setBlocked]
        );
    }

    /**
     * @return integer
     */
    protected function getInvoiceId(): int
    {
        return $this->getInvoice()->getId();
    }
}
