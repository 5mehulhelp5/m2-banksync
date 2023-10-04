<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Display;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory as OrderAddressCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

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
        $this->collection->join(['invoice' => 'sales_invoice'], 'invoice.entity_id = main_table.invoice_id', []);
        $this->collection->join(['order' => 'sales_order'], 'order.entity_id = invoice.order_id', []);

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

    /**
     * @return array
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getData()
    {
        $data = parent::getData();

        foreach ($data['items'] as $key => $item) {
            $invoice = $this->invoiceRepository->get($item['invoice_id']);
            $data['items'][$key] = array_replace($item, [
                'email_address' => $invoice->getOrder()->getCustomerEmail(),
                'invoice_date' => $invoice->getCreatedAt(),
                'invoice_increment_id' => $this->display->getObjectLink($invoice),
                'is_sent' => (int)!empty($item['sent_at']),
            ]);
        }

        return $data;
    }

    /**
     * @param Filter $filter
     * @return void
     */
    protected function setFilterIsSent(Filter $filter): void
    {
        $filter->setField('sent_at')
            ->setConditionType($filter->getValue() ? 'notnull' : 'null')
            ->setValue(true);
    }

    /**
     * @param Filter $filter
     *
     * @return void
     */
    public function addFilter(Filter $filter)
    {
        $processors = [
            'email_address' => 'order.customer_email',
            'invoice_date' => 'invoice.created_at',
            'invoice_increment_id' => 'invoice.increment_id',
            'is_sent' => [$this, 'setFilterIsSent'],
        ];

        $processor = $processors[$filter->getField()] ?? null;
        if (is_callable($processor)) {
            $processor($filter);
        } elseif (is_string($processor)) {
            $filter->setField($processor);
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
        $changes = [
            'email_address' => 'order.customer_email',
            'invoice_date' => 'invoice.created_at',
            'invoice_increment_id' => 'invoice.increment_id',
            'is_sent' => 'sent_at',
        ];

        if (isset($changes[$field])) {
            $field = $changes[$field];
        }

        parent::addOrder($field, $direction);
    }
}
