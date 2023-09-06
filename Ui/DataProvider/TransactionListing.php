<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Display;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Sales\Model\ResourceModel\Order\Address\CollectionFactory as OrderAddressCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class TransactionListing extends AbstractDataProvider
{
    protected UrlInterface $urlBuilder;
    protected Config $config;
    protected Display $display;
    protected InvoiceRepository $invoiceRepository;
    protected CreditmemoRepository $creditmemoRepository;
    protected Logger $logger;
    protected InvoiceCollectionFactory $invoiceCollectionFactory;
    protected CreditmemoCollectionFactory $creditmemoCollectionFactory;
    protected OrderCollectionFactory $orderCollectionFactory;
    protected CustomerCollectionFactory $customerCollectionFactory;
    protected OrderAddressCollectionFactory $orderAddressCollectionFactory;
    private CustomerFactory $customerFactory;
    private CustomerResource $customerResource;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        Config $config,
        Display $display,
        UrlInterface $urlBuilder,
        InvoiceRepository $invoiceRepository,
        CreditmemoRepository $creditmemoRepository,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        CreditmemoCollectionFactory $creditmemoCollectionFactory,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerCollectionFactory $customerCollectionFactory,
        OrderAddressCollectionFactory $orderAddressCollectionFactory,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        Logger $logger,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        $this->config = $config;
        $this->display = $display;
        $this->urlBuilder = $urlBuilder;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->orderAddressCollectionFactory = $orderAddressCollectionFactory;
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;
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

        foreach ($data['items'] as &$item) {
            try {
                // Add 'document' field
                $url = $this->urlBuilder->getUrl(
                    'sales/' . $item['document_type'] . '/view',
                    ['invoice_id' => $item['document_id']]
                );
                $document = (
                    $item['document_type'] == 'invoice'
                    ? $this->invoiceRepository
                    : $this->creditmemoRepository
                )->get($item['document_id']);

                $item['document'] = "<a href='$url'>" . $document->getIncrementId() . "</a>";
                $item['document_name'] = $this->display->getCustomerNamesForListing($document->getOrder());
                $item['document_amount'] = $document->getGrandTotal();
                $item['document_date'] = $document->getCreatedAt();
                $orderUrl = $this->urlBuilder->getUrl(
                    'sales/order/view',
                    ['order_id' => $document->getOrder()->getId()]
                );
                $item['order_increment_id'] = "<a href='$orderUrl'>{$document->getOrder()->getIncrementId()}</a>";
                $item['payment_method'] = $document->getOrder()->getPayment()->getMethodInstance()->getTitle();

                $customerId = $document->getOrder()->getCustomerId();
                if ($customerId) {
                    $customer = $this->customerFactory->create();
                    $this->customerResource->load($customer, $customerId);
                    $customerUrl = $this->urlBuilder->getUrl(
                        'customer/index/edit',
                        ['id' => $customerId]
                    );
                    $item['customer_increment_id'] = "<a href='$customerUrl'>{$customer->getIncrementId()}</a>";
                } else {
                    $item['customer_increment_id'] = "-";
                }

            } catch (Exception $e) {
                $this->logger->error($e);
                $item['document'] = "[Not found]";
            }
        }

        return $data;
    }

    /**
     * @param Filter $filter
     * @param int[]  $orderIds
     */
    protected function setFilterByOrderIds(Filter $filter, array $orderIds): void
    {
        $creditMemoIds = $this->config->isSupportCreditmemos()
            ? $this->creditmemoCollectionFactory->create()
                ->addFieldToFilter('order_id', ['in' => $orderIds])
                ->getAllIds()
            : [];

        $invoiceIds = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('order_id', ['in' => $orderIds])
            ->getAllIds();

        $filter->setField('document_id')
            ->setConditionType('in')
            ->setValue(implode(',', array_merge($creditMemoIds, $invoiceIds)));
    }

    /**
     * @param Filter $filter
     *
     * @return void
     */
    public function addFilter(Filter $filter)
    {
        if ($filter->getField() === 'order_increment_id') {
            $orderIds = $this->orderCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getAllIds();

            $this->setFilterByOrderIds($filter, $orderIds);
        }

        if ($filter->getField() === 'document') {
            $creditMemoIds = $this->config->isSupportCreditmemos()
                ? $this->creditmemoCollectionFactory->create()
                    ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                    ->getAllIds()
                : [];

            $invoiceIds = $this->invoiceCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getAllIds();

            $filter->setField('document_id')
                ->setConditionType('in')
                ->setValue(implode(',', array_merge($creditMemoIds, $invoiceIds)));
        }

        if ($filter->getField() === 'customer_increment_id') {
            /** @var Customer $customer */

            $customer = $this->customerCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getFirstItem();

            $orderIds = $this->orderCollectionFactory->create()
                ->addFieldToFilter('customer_id', $customer->getId())
                ->getAllIds();

            $this->setFilterByOrderIds($filter, $orderIds);
        }

        if ($filter->getField() == 'document_name') {
            $orderIds = $this->orderAddressCollectionFactory->create()
                ->addFieldToFilter(
                    ['firstname', 'lastname', 'company'],
                    [
                        [$filter->getConditionType() => $filter->getValue()],
                        [$filter->getConditionType() => $filter->getValue()],
                        [$filter->getConditionType() => $filter->getValue()],
                    ]
                )
                ->getColumnValues('parent_id');
            $this->setFilterByOrderIds($filter, $orderIds);
        }

        parent::addFilter($filter);
    }
}
