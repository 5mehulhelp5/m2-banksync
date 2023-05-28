<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Exception;
use Ibertrand\BankSync\Helper\Data;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory;
use Ibertrand\BankSync\Model\TempTransaction;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Psr\Log\LoggerInterface;

class TempTransactionListing extends AbstractDataProvider
{
    private UrlInterface $urlBuilder;
    private CreditmemoRepository $creditmemoRepository;
    private InvoiceRepository $invoiceRepository;
    private LoggerInterface $logger;
    private MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    private Data $helper;
    private CustomerFactory $customerFactory;
    private CustomerResource $customerResource;
    private \Magento\Framework\Pricing\Helper\Data $priceHelper;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        UrlInterface $urlBuilder,
        InvoiceRepository $invoiceRepository,
        CreditmemoRepository $creditmemoRepository,
        MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        Data $helper,
        LoggerInterface $logger,
        \Magento\Framework\Pricing\Helper\Data $priceHelper,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        $this->urlBuilder = $urlBuilder;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;
        $this->helper = $helper;
        $this->logger = $logger;
        $this->priceHelper = $priceHelper;
        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $meta,
            $data
        );
    }

    /**
     * @param int $customerId
     *
     * @return Customer
     */
    public function loadCustomer(int $customerId): Customer
    {
        $customer = $this->customerFactory->create();
        $this->customerResource->load($customer, $customerId);
        return $customer;
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
        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
        $incrementId = $object->getIncrementId();
        $class = in_array($incrementId, array_keys($matchedTexts)) ? 'banksync-matched-text' : '';
        if ($class == '' && str_ends_with($incrementId, '00')) {
            $incrementId = substr($incrementId, 0, -2);
            $class = in_array($incrementId, array_keys($matchedTexts)) ? 'banksync-matched-text' : '';
        }
        return "<a class='$class' href='$url'>$incrementId</a>";
    }

    public function getData()
    {
        $data = parent::getData();

        $acceptanceThreshold = $this->helper->getAcceptConfidenceThreshold();
        $absoluteThreshold = $this->helper->getAbsoluteConfidenceThreshold();

        $allConfidences = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', ['in' => array_column($data['items'], 'entity_id')]);

        foreach ($data['items'] as &$item) {
            /** @var TempTransaction $tempTransaction */
            $tempTransaction = $this->collection->getItemById($item['entity_id']);
            $matches = $allConfidences->getItemsByColumnValue('temp_transaction_id', $item['entity_id']);
            usort($matches, fn ($b, $a) => $a->getConfidence() <=> $b->getConfidence());

            $confidentMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $acceptanceThreshold);
            $absoluteMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $absoluteThreshold);

            $item['document_type'] = $item['amount'] > 0 ? 'invoice' : 'creditmemo';
            $matchCount = count($matches);
            $item['document_count'] = $matchCount;
            if ($matchCount <= 0) {
                $item['amount'] = $this->priceHelper->currency($tempTransaction->getAmount());
            } else {
                try {
                    // Add 'document' field
                    $documentId = $matches[0]->getDocumentId();
                    $document = $item['document_type'] == 'invoice'
                        ? $this->invoiceRepository->get($documentId)
                        : $this->creditmemoRepository->get($documentId);

                    $payerName = $tempTransaction->getPayerName();

                    $order = $document->getOrder();
                    $names = array_filter(array_unique([
                        trim($order->getCustomerName() ?? ""),
                        trim(($order->getBillingAddress()->getFirstname() ?? "") . ' ' . ($order->getBillingAddress()->getLastname() ?? "")),
                        trim($order->getBillingAddress()->getCompany() ?? ""),
                        trim(($order->getShippingAddress()->getFirstname() ?? "") . ' ' . ($order->getShippingAddress()->getLastname() ?? "")),
                        trim($order->getShippingAddress()->getCompany() ?? ""),
                    ]));
                    $documentName = implode("<br>", $names);

                    if (count($matches) == 1 || count($confidentMatches) == 1 || count($absoluteMatches) == 1) {
                        $purposeMatches = $this->helper->getPurposeMatches($tempTransaction, $document);
                        $purpose = $tempTransaction->getPurpose();
                        foreach ($purposeMatches as $match => $score) {
                            $purpose = str_replace($match, "<span class='banksync-matched-text'>$match</span>", $purpose);
                            $documentName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $documentName);
                        }
                        $item['purpose'] = $purpose;

                        $nameMatches = $this->helper->getNameMatches($tempTransaction, $document);

                        $payerName = $tempTransaction->getPayerName();
                        foreach (array_keys($nameMatches) as $match) {
                            $payerName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $payerName);
                            $documentName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $documentName);
                        }
                        $amountIsMatched = abs(abs($tempTransaction->getAmount()) - $document->getGrandTotal()) < 0.01;
                        $amountClass = $amountIsMatched ? 'banksync-matched-text' : '';
                    } else {
                        $purposeMatches = [];
                        $amountClass = "";
                    }

                    $item['amount'] = "<span class='$amountClass'>{$this->priceHelper->currency($tempTransaction->getAmount())}</span>";
                    $item['document_amount'] = "<span class='$amountClass'>{$this->priceHelper->currency($document->getGrandTotal())}</span>";

                    $item['document_date'] = $document->getCreatedAt();
                    $item['document'] = $this->getObjectLink($document, $purposeMatches);
                    $item['order_increment_id'] = $this->getObjectLink($document->getOrder(), $purposeMatches);
                    $item['payer_name'] = $payerName;
                    $item['document_name'] = $documentName;

                    $customerId = $document->getOrder()->getCustomerId();
                    if ($customerId) {
                        $customer = $this->loadCustomer($customerId);
                        $item['customer_increment_id'] = $this->getObjectLink($customer, $purposeMatches);
                    } else {
                        $item['customer_increment_id'] = '-';
                    }
                } catch (Exception $e) {
                    $this->logger->error($e);
                    $item['document'] = "[Not found]";
                }
            }

            // Add 'confidence' field with color
            $confidence = count($matches) > 0
                ? max(array_map(fn ($match) => $match->getConfidence(), $matches))
                : null;


            if ($confidence === null) {
                $text = '-';
                $class = 'low';
            } else {
                $text = $confidence;

                $class = $confidence >= $acceptanceThreshold
                && (count($confidentMatches) == 1 || count($absoluteMatches) == 1)
                    ? 'high'
                    : 'low';
            }
            $item['allow_book'] = $matchCount == 1 || $class == 'high';
            $item['match_confidence'] = "<div class='banksync-confidence-$class'>$text</div>";
        }

        return $data;
    }
}
