<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Exception;
use Ibertrand\BankSync\Helper\Data;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
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

    protected function getObjectLink($object, $matchedTexts)
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

    public function getData()
    {
        $data = parent::getData();

        $acceptanceThreshold = $this->helper->getAcceptConfidenceThreshold();
        $absoluteThreshold = $this->helper->getAbsoluteConfidenceThreshold();

        $allConfidences = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', ['in' => array_column($data['items'], 'entity_id')]);

        foreach ($data['items'] as &$item) {
            $tempTransaction = $this->collection->getItemById($item['entity_id']);
            $matches = $allConfidences->getItemsByColumnValue('temp_transaction_id', $item['entity_id']);
            usort($matches, fn ($b, $a) => $a->getConfidence() <=> $b->getConfidence());

            $confidentMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $acceptanceThreshold);
            $absoluteMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $absoluteThreshold);

            $item['document_type'] = $item['amount'] > 0 ? 'invoice' : 'creditmemo';
            $matchCount = count($matches);
            $item['document_count'] = $matchCount;
            if ($matchCount > 0) {
                try {
                    // Add 'document' field
                    $documentId = $matches[0]->getDocumentId();
                    $document = $item['document_type'] == 'invoice'
                        ? $this->invoiceRepository->get($documentId)
                        : $this->creditmemoRepository->get($documentId);

                    if (count($matches) == 1 || count($confidentMatches) == 1 || count($absoluteMatches) == 1) {
                        $purposeMatches = $this->helper->getPurposeMatches($tempTransaction, $document);
                        $purpose = $tempTransaction->getPurpose();
                        foreach ($purposeMatches as $match => $score) {
                            $purpose = str_replace($match, "<span class='banksync-matched-text'>$match</span>", $purpose);
                        }
                        $item['purpose'] = $purpose;
                    } else {
                        $purposeMatches = [];
                    }

                    $item['document_name'] = $document->getOrder()->getCustomerName();
                    $item['document_amount'] = $document->getGrandTotal();
                    $item['document_date'] = $document->getCreatedAt();
                    $item['document'] = $this->getObjectLink($document, $purposeMatches);
                    $item['order_increment_id'] = $this->getObjectLink($document->getOrder(), $purposeMatches);

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
