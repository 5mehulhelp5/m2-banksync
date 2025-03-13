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
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
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
    const JOIN_CONFIG = [
        'invoice' => [
            'table_name' => 'sales_invoice',
            'on_clause' => 'invoice.entity_id = main_table.invoice_id',
            'needed_joins' => [],
        ],
        'order' => [
            'table_name' => 'sales_order',
            'on_clause' => 'order.entity_id = invoice.order_id',
            'needed_joins' => ['invoice'],
        ],
    ];
    const JOINS_NEEDED = [
        'email_address' => ['order'],
        'invoice_date' => ['invoice'],
        'invoice_increment_id' => ['invoice'],
    ];
    protected array $joinedTables = [];
    protected PriceHelper $priceHelper;

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
        PriceHelper $priceHelper,
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
        $this->priceHelper = $priceHelper;

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
            $order = $invoice->getOrder();
            $billingAddress = $order->getBillingAddress();
            $names = [
                trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname()),
                trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname()),
                $billingAddress->getCompany(),
            ];
            $names = array_unique(array_filter($names));
            $data['items'][$key] = array_replace($item, [
                'email_address' => $order->getCustomerEmail(),
                'invoice_date' => $invoice->getCreatedAt(),
                'invoice_increment_id' => $this->display->getObjectLink($invoice),
                'is_sent' => (int)!empty($item['sent_at']),
                'is_archived' => (int)!empty($item['archived_at']),
                'name' => implode("<br>", $names),
                'document_amount' => $this->priceHelper->currency($invoice->getGrandTotal()),
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
     * @return void
     */
    protected function setFilterIsArchived(Filter $filter): void
    {
        $filter->setField('archived_at')
            ->setConditionType($filter->getValue() ? 'notnull' : 'null')
            ->setValue(true);
    }

    protected function setFilterName(Filter $filter): void
    {
        // Normalize the filter value by replacing multiple consecutive spaces with a single space
        $filterValue = trim(preg_replace('/\s\s+/u', ' ', $filter->getValue()));

        // Fetch invoice IDs related to dunnings and corresponding invoices, orders, and billing addresses.
        $allInvoiceIdsWithDunnings = $this->dunningCollectionFactory->create()->getColumnValues('invoice_id');
        $allInvoicesWithDunnings = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $allInvoiceIdsWithDunnings]);
        $allOrderIdsWithDunnings = $allInvoicesWithDunnings->getColumnValues('order_id');
        $allBillingAddressesWithDunnings = $allInvoicesWithDunnings->getColumnValues('billing_address_id');

        // Use customer name to filter matching orders.
        $matchingOrders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $allOrderIdsWithDunnings]);
        $matchingOrders->getSelect()
            ->where(
                'REGEXP_REPLACE(CONCAT(customer_firstname, " ", customer_lastname), "\\\\s\\\\s+", " ") LIKE ?',
                $filterValue
            );
        $matchingOrderIds = $matchingOrders->getColumnValues('entity_id');

        // Similarly, filter billing addresses by name or company.
        $matchingBillingAddresses = $this->orderAddressCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $allBillingAddressesWithDunnings]);
        $matchingBillingAddresses->getSelect()
            ->where(
                'REGEXP_REPLACE(CONCAT(firstname, " ", lastname), "\\\\s\\\\s+", " ") LIKE ? ' .
                'OR REGEXP_REPLACE(company, "\\\\s\\\\s+", " ") LIKE ?',
                $filterValue
            );

        // Deduplicate and combine matching order IDs from orders and billing addresses for efficient filtering.
        $matchingOrderIds = array_unique(array_merge(
            $matchingOrderIds,
            $matchingBillingAddresses->getColumnValues('parent_id')
        ));
        $matchingOrderIds = array_combine($matchingOrderIds, $matchingOrderIds);

        // Directly filter invoices in memory using the reduced set of order IDs.
        $matchingInvoices = array_filter(
            $allInvoicesWithDunnings->getItems(),
            fn ($invoice) => isset($matchingOrderIds[$invoice->getOrderId()])
        );
        $matchingInvoiceIds = array_map(fn ($invoice) => $invoice->getId(), $matchingInvoices);

        // Set the refined invoice ID filter.°
        $filter->setField('invoice_id')
            ->setConditionType('in')
            ->setValue(implode(',', $matchingInvoiceIds));
    }

    /**
     * @param string $joinIdent
     * @return void
     */
    protected function join(string $joinIdent): void
    {
        if (isset($this->joinedTables[$joinIdent])) {
            return;
        }
        $joinConfig = self::JOIN_CONFIG[$joinIdent];
        foreach ($joinConfig['needed_joins'] ?? [] as $neededJoin) {
            $this->join($neededJoin);
        }

        $this->collection->join(
            [$joinIdent => $joinConfig['table_name']],
            $joinConfig['on_clause'],
            []
        );
        $this->joinedTables[$joinIdent] = true;
    }

    /**
     * @param Filter $filter
     *
     * @return void
     */
    public function addFilter(Filter $filter)
    {
        foreach (self::JOINS_NEEDED[$filter->getField()] ?? [] as $join) {
            $this->join($join);
        }

        $processors = [
            'email_address' => 'order.customer_email',
            'invoice_date' => 'invoice.created_at',
            'invoice_increment_id' => 'invoice.increment_id',
            'is_sent' => [$this, 'setFilterIsSent'],
            'is_archived' => [$this, 'setFilterIsArchived'],
            'name' => [$this, 'setFilterName'],
            'document_amount' => 'invoice.grand_total',
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
        foreach (self::JOINS_NEEDED[$field] ?? [] as $join) {
            $this->join($join);
        }

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
