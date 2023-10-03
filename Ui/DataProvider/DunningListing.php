<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Display;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory as OrderAddressCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Zend_Db;
use Zend_Db_Select;

class DunningListing extends AbstractDataProvider
{
    protected UrlInterface $urlBuilder;
    protected Config $config;
    protected Display $display;
    protected InvoiceRepository $invoiceRepository;
    protected Logger $logger;
    protected InvoiceCollectionFactory $invoiceCollectionFactory;
    protected OrderCollectionFactory $orderCollectionFactory;
    protected CustomerCollectionFactory $customerCollectionFactory;
    protected OrderAddressCollectionFactory $orderAddressCollectionFactory;
    protected CollectionFactory $dunningCollectionFactory;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        Config $config,
        Display $display,
        UrlInterface $urlBuilder,
        InvoiceRepository $invoiceRepository,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerCollectionFactory $customerCollectionFactory,
        OrderAddressCollectionFactory $orderAddressCollectionFactory,
        CollectionFactory $dunningCollectionFactory,
        Logger $logger,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        $this->config = $config;
        $this->display = $display;
        $this->urlBuilder = $urlBuilder;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->orderAddressCollectionFactory = $orderAddressCollectionFactory;
        $this->dunningCollectionFactory = $dunningCollectionFactory;

        $this->logger = $logger;
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $meta,
            $data
        );
    }

    public function getData()
    {
        $data = parent::getData();

        foreach ($data['items'] as $key => $item) {
            $invoice = $this->invoiceRepository->get($item['invoice_id']);
            $item['invoice_increment_id'] = $this->display->getObjectLink($invoice);
            $item['dunning_type'] = $this->config->getDunningTypeLabel($item['dunning_type'], $invoice->getStoreId());
            $item['email_address'] = $invoice->getOrder()->getCustomerEmail();
            $item['is_sent'] = (int)!empty($item['sent_at']);

            $data['items'][$key] = $item;
        }

        return $data;
    }


    /**
     * @param Filter $filter
     *
     * @return void
     */
    public function addFilter(Filter $filter)
    {
        if ($filter->getField() === 'is_sent') {
            $filter->setField('sent_at')
                ->setConditionType($filter->getValue() ? 'notnull' : 'null')
                ->setValue(true);
        }

        if ($filter->getField() === 'invoice_increment_id') {
            $invoiceCollection = $this->invoiceCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getAllIds();
            $filter->setField('invoice_id')
                ->setConditionType('in')
                ->setValue(implode(',', $invoiceCollection));
        }

        if ($filter->getField() === 'email_address') {
            $relevantInvoiceIds = $this->dunningCollectionFactory->create()
                ->join(['invoice' => 'sales_invoice'], 'invoice.entity_id = main_table.invoice_id', [])
                ->getSelect()
                ->reset(Zend_Db_Select::COLUMNS)
                ->columns('invoice.entity_id')
                ->query()
                ->fetchAll(Zend_Db::FETCH_COLUMN);
            $invoiceCollection = $this->invoiceCollectionFactory->create()
                ->join(['order' => 'sales_order'], 'order.entity_id = main_table.order_id', [])
                ->addFieldToFilter('main_table.entity_id', ['in' => $relevantInvoiceIds])
                ->addFieldToFilter('order.customer_email', [$filter->getConditionType() => $filter->getValue()]);
            $this->logger->info($invoiceCollection->getSelect()->__toString());
            $invoiceCollection = $invoiceCollection->getAllIds();

            $filter->setField('invoice_id')
                ->setConditionType('in')
                ->setValue(implode(',', $invoiceCollection));
        }

        parent::addFilter($filter);
    }

    /**
     * @param $field
     * @param $direction
     * @return void
     */
    public function addOrder($field, $direction)
    {
        if ($field === 'invoice_increment_id') {
            $field = 'invoice_id';
        }
        if ($field === 'is_sent') {
            $field = 'sent_at';
        }
        parent::addOrder($field, $direction);
    }
}
