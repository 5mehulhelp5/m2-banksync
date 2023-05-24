<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Ui\DataProvider\AbstractDataProvider;

class TempTransactionSearchDocumentListing extends AbstractDataProvider
{
    protected UrlInterface $urlBuilder;
    protected InvoiceCollectionFactory $invoiceCollectionFactory;
    protected CreditmemoCollectionFactory $creditmemoCollectionFactory;
    protected TempTransactionRepository $tempTransactionRepository;
    protected Http $request;
    protected OrderCollectionFactory $orderCollectionFactory;
    private CustomerResource $customerResource;
    private CustomerFactory $customerFactory;

    /**
     * @param string                      $name
     * @param string                      $primaryFieldName
     * @param string                      $requestFieldName
     * @param UrlInterface                $urlBuilder
     * @param InvoiceCollectionFactory    $invoiceCollectionFactory
     * @param CreditmemoCollectionFactory $creditmemoCollectionFactory
     * @param TempTransactionRepository   $tempTransactionRepository
     * @param OrderCollectionFactory      $orderCollectionFactory
     * @param CustomerFactory             $customerFactory
     * @param CustomerResource            $customerResource
     * @param Http                        $request
     * @param array                       $meta
     * @param array                       $data
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        UrlInterface $urlBuilder,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        CreditmemoCollectionFactory $creditmemoCollectionFactory,
        TempTransactionRepository $tempTransactionRepository,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        Http $request,
        array $meta = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;

        $this->request = $request;

        $this->createCollection();

        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $meta,
            $data
        );
    }

    /**
     * @return TempTransaction
     * @throws NoSuchEntityException
     */
    protected function getTempTransaction(): TempTransaction
    {
        return $this->tempTransactionRepository->getById($this->request->getParam('id'));
    }

    /**
     * @return void
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    protected function createCollection()
    {
        $tempTransaction = $this->getTempTransaction();
        $documentType = $tempTransaction->getDocumentType();

        $this->collection = $documentType
            ? $this->invoiceCollectionFactory->create()
            : $this->creditmemoCollectionFactory->create();
    }

    /**
     * @param int $id
     *
     * @return Invoice|Creditmemo
     */
    protected function getDocument(int $id): Invoice|Creditmemo
    {
        /** @var Invoice|Creditmemo $document To silence IDE warnings about mismatching return value */
        $document = $this->collection->getItemById($id);
        return $document;
    }

    /**
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getData()
    {
        $data = parent::getData();

        $tempTransaction = $this->getTempTransaction();

        foreach ($data['items'] as &$item) {
            $document = $this->getDocument($item['entity_id']);

            $item['document_type'] = $tempTransaction->getDocumentType();
            $item['customer_name'] = $document->getOrder()->getCustomerName();

            $item['transaction_date'] = $tempTransaction->getTransactionDate();
            $item['transaction_amount'] = $tempTransaction->getAmount();
            $item['transaction_payer_name'] = $tempTransaction->getPayerName();
            $item['transaction_purpose'] = $tempTransaction->getPurpose();
            $item['transaction_id'] = $tempTransaction->getId();

            $orderUrl = $this->urlBuilder->getUrl(
                'sales/order/view',
                ['order_id' => $document->getOrder()->getId()]
            );
            $item['order_increment_id'] = "<a href='$orderUrl'>{$document->getOrder()->getIncrementId()}</a>";

            $customerId = $document->getOrder()->getCustomerId();
            if ($customerId) {
                $customer = $this->customerFactory->create();
                $this->customerResource->load($customer, $customerId);
                $customerUrl = $this->urlBuilder->getUrl(
                    'customer/index/edit',
                    ['id' => $customerId]
                );
                /** @noinspection PhpUndefinedMethodInspection */
                $item['customer_increment_id'] = "<a href='{$customerUrl}'>{$customer->getIncrementId()}</a>";
            } else {
                $item['customer_increment_id'] = "-";
            }

            $documentUrl = $this->urlBuilder->getUrl(
                "sales/{$tempTransaction->getDocumentType()}/view",
                [$tempTransaction->getDocumentType() . '_id' => $document->getId()]
            );
            $item['increment_id'] = "<a href='$documentUrl'>{$document->getIncrementId()}</a>";
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
        if ($filter->getField() === 'order_increment_id') {
            /** @var Order $order */

            $order = $this->orderCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType()=> $filter->getValue()])
                ->getFirstItem();

            $filter->setField('order_id')
                ->setValue($order->getId());
        }

        parent::addFilter($filter);
    }
}
