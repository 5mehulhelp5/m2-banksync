<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Helper\Data as BankSyncHelper;
use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
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
    protected CustomerResource $customerResource;
    protected CustomerFactory $customerFactory;
    protected BankSyncHelper $helper;
    protected CustomerCollectionFactory $customerCollectionFactory;
    private PriceHelper $priceHelper;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param UrlInterface $urlBuilder
     * @param InvoiceCollectionFactory $invoiceCollectionFactory
     * @param CreditmemoCollectionFactory $creditmemoCollectionFactory
     * @param TempTransactionRepository $tempTransactionRepository
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param CustomerFactory $customerFactory
     * @param CustomerResource $customerResource
     * @param Http $request
     * @param array $meta
     * @param array $data
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function __construct(
        $name,
                                    $primaryFieldName,
                                    $requestFieldName,
        UrlInterface                $urlBuilder,
        InvoiceCollectionFactory    $invoiceCollectionFactory,
        CreditmemoCollectionFactory $creditmemoCollectionFactory,
        TempTransactionRepository   $tempTransactionRepository,
        OrderCollectionFactory      $orderCollectionFactory,
        CustomerFactory             $customerFactory,
        CustomerResource            $customerResource,
        Http                        $request,
        BankSyncHelper              $helper,
        CustomerCollectionFactory   $customerCollectionFactory,
        PriceHelper                 $priceHelper,
        array                       $meta = [],
        array                       $data = []
    )
    {
        $this->urlBuilder = $urlBuilder;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;
        $this->helper = $helper;
        $this->customerCollectionFactory = $customerCollectionFactory;
        $this->priceHelper = $priceHelper;

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

    protected function getObjectLink(DataObject $object, array $matchedTexts): string
    {
        if ($object instanceof Invoice) {
            $url = $this->urlBuilder->getUrl('sales/invoice/view', ['invoice_id' => $object->getId()]);
        } elseif ($object instanceof Creditmemo) {
            $url = $this->urlBuilder->getUrl('sales/creditmemo/view', ['creditmemo_id' => $object->getId()]);
        } elseif ($object instanceof Order) {
            $url = $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $object->getId()]);
        } elseif ($object instanceof Customer) {
            $url = $this->urlBuilder->getUrl('customer/index/edit', ['id' => $object->getId()]);
        } else {
            return '';
        }
        $class = in_array($object->getIncrementId(), array_keys($matchedTexts)) ? 'banksync-matched-text' : '';
        return "<a class='$class' href='$url'>{$object->getIncrementId()}</a>";
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
            $order = $document->getOrder();
            $purposeMatches = $this->helper->getPurposeMatches($tempTransaction, $document);

            $item['document_type'] = $tempTransaction->getDocumentType();
            $item['transaction_date'] = $tempTransaction->getTransactionDate();
            $item['transaction_id'] = $tempTransaction->getId();
            $item['increment_id'] = $this->getObjectLink($document, $purposeMatches);
            $item['order_increment_id'] = $this->getObjectLink($document->getOrder(), $purposeMatches);

            $amountIsMatched = abs(abs($tempTransaction->getAmount()) - $document->getGrandTotal()) < 0.01;
            $amountClass = $amountIsMatched ? 'banksync-matched-text' : '';
            $item['transaction_amount'] = "<span class='$amountClass'>{$this->priceHelper->currency($tempTransaction->getAmount())}</span>";
            $item['grand_total'] = "<span class='$amountClass'>{$this->priceHelper->currency($document->getGrandTotal())}</span>";

            $customerId = $document->getOrder()->getCustomerId();
            if ($customerId) {
                $customer = $this->customerFactory->create();
                $this->customerResource->load($customer, $customerId);
                $item['customer_increment_id'] = $this->getObjectLink($customer, $purposeMatches);
            } else {
                $item['customer_increment_id'] = "-";
            }

            $names = array_filter(array_unique([
                trim($order->getCustomerName() ?? ""),
                trim(($order->getBillingAddress()->getFirstname() ?? "") . ' ' . ($order->getBillingAddress()->getLastname() ?? "")),
                trim($order->getBillingAddress()->getCompany() ?? ""),
                trim(($order->getShippingAddress()->getFirstname() ?? "") . ' ' . ($order->getShippingAddress()->getLastname() ?? "")),
                trim($order->getShippingAddress()->getCompany() ?? ""),
            ]));
            $nameMatches = $this->helper->getNameMatches($tempTransaction, $document);

            $documentName = implode("<br>", $names);
            $purpose = $tempTransaction->getPurpose();
            $payerName = $tempTransaction->getPayerName();

            foreach (array_keys($purposeMatches) as $match) {
                $purpose = str_replace($match, "<span class='banksync-matched-text'>$match</span>", $purpose);
                $documentName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $documentName);
            }
            foreach (array_keys($nameMatches) as $match) {
                $payerName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $payerName);
                $documentName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $documentName);
            }

            $item['transaction_purpose'] = $purpose;
            $item['customer_name'] = $documentName;
            $item['transaction_payer_name'] = $payerName;

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
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getFirstItem();

            $filter->setField('order_id')
                ->setValue($order->getId());
        }

        if ($filter->getField() === 'customer_increment_id') {
            /** @var Customer $customer */

            $customer = $this->customerCollectionFactory->create()
                ->addFieldToFilter('increment_id', [$filter->getConditionType() => $filter->getValue()])
                ->getFirstItem();

            $filter->setField('customer_id')
                ->setValue($customer->getId());
        }

        parent::addFilter($filter);
    }
}
