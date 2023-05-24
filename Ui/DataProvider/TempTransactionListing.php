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
use Magento\Sales\Model\Order\CreditmemoRepository;
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

    public function getData()
    {
        $data = parent::getData();

        $acceptanceThreshold = $this->helper->getAcceptConfidenceThreshold();
        $absoluteThreshold = $this->helper->getAbsoluteConfidenceThreshold();

        $allConfidences = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', ['in' => array_column($data['items'], 'entity_id')]);

        foreach ($data['items'] as &$item) {
            $matches = $allConfidences->getItemsByColumnValue('temp_transaction_id', $item['entity_id']);
            usort($matches, fn ($b, $a) => $a->getConfidence() <=> $b->getConfidence());

            $item['document_type'] = $item['amount'] > 0 ? 'invoice' : 'creditmemo';
            $matchCount = count($matches);
            $item['document_count'] = $matchCount;
            if ($matchCount > 0) {
                try {
                    // Add 'document' field
                    $documentId = $matches[0]->getDocumentId();
                    $url = $this->urlBuilder->getUrl(
                        'sales/' . $item['document_type'] . '/view',
                        [$item['document_type'] . '_id' => $documentId]
                    );
                    $document = $item['document_type'] == 'invoice'
                        ? $this->invoiceRepository->get($documentId)
                        : $this->creditmemoRepository->get($documentId);

                    $item['document'] = "<a href='$url'>" . $document->getIncrementId() . "</a>";
                    $item['document_name'] = $document->getOrder()->getCustomerName();
                    $item['document_amount'] = $document->getGrandTotal();
                    $item['document_date'] = $document->getCreatedAt();
                    $orderUrl = $this->urlBuilder->getUrl(
                        'sales/order/view',
                        ['order_id' => $document->getOrder()->getId()]
                    );
                    $item['order_increment_id'] = "<a href='$orderUrl'>{$document->getOrder()->getIncrementId()}</a>";

                    $customerId = $document->getOrder()->getCustomerId();
                    if ($customerId) {
                        $customer = $this->loadCustomer($customerId);

                        /** @noinspection PhpUndefinedMethodInspection */
                        $customerIncrementId = $customer->getIncrementId();
                        $customerUrl = $this->urlBuilder->getUrl(
                            'customer/index/edit',
                            ['id' => $customerId]
                        );
                        $item['customer_increment_id'] = "<a href='{$customerUrl}'>$customerIncrementId</a>";
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

            $confidentMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $acceptanceThreshold);
            $absoluteConfidentMatches = array_filter($matches, fn ($m) => $m->getConfidence() >= $absoluteThreshold);

            if ($confidence === null) {
                $text = '-';
                $class = 'low';
            } else {
                $text = $confidence;

                $class = $confidence >= $acceptanceThreshold
                && (count($confidentMatches) == 1 || count($absoluteConfidentMatches) == 1)
                    ? 'high'
                    : 'low';
            }
            $item['allow_book'] = $matchCount == 1 || $class == 'high';
            $item['match_confidence'] = "<div class='banksync-confidence-$class'>$text</div>";
        }

        return $data;
    }
}
