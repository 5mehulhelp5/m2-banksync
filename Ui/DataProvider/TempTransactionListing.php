<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Display;
use Ibertrand\BankSync\Helper\Matching;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory;
use Ibertrand\BankSync\Model\TempTransaction;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\Pricing\Helper\Data;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Ui\DataProvider\AbstractDataProvider;

class TempTransactionListing extends AbstractDataProvider
{
    protected UrlInterface $urlBuilder;
    protected CreditmemoRepository $creditmemoRepository;
    protected InvoiceRepository $invoiceRepository;
    protected Logger $logger;
    protected MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    protected CustomerFactory $customerFactory;
    protected CustomerResource $customerResource;
    protected Data $priceHelper;
    protected Matching $matching;
    protected Display $display;
    protected Config $config;

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
        Matching $matching,
        Display $displayHelper,
        Config $config,
        Logger $logger,
        Data $priceHelper,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        $this->urlBuilder = $urlBuilder;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;
        $this->matching = $matching;
        $this->display = $displayHelper;
        $this->config = $config;
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

    public function getData()
    {
        $data = parent::getData();

        $acceptanceThreshold = $this->config->getAcceptConfidenceThreshold();
        $absoluteThreshold = $this->config->getAbsoluteConfidenceThreshold();

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
                    $documentName = $this->display->getCustomerNamesForListing($order);

                    if (count($matches) == 1 || count($confidentMatches) == 1 || count($absoluteMatches) == 1) {
                        $purposeMatches = $this->matching->getPurposeMatches($tempTransaction, $document);
                        $purpose = $tempTransaction->getPurpose();
                        foreach ($purposeMatches as $match => $score) {
                            $purpose = str_replace($match, "<span class='banksync-matched-text'>$match</span>", $purpose);
                            $documentName = preg_replace('/' . preg_quote($match, '/') . '/i', '<span class="banksync-matched-text">$0</span>', $documentName);
                        }
                        $item['purpose'] = $purpose;

                        $nameMatches = $this->matching->getNameMatches($tempTransaction, $document);

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
                    $item['document'] = $this->display->getObjectLink($document, $purposeMatches);
                    $item['order_increment_id'] = $this->display->getObjectLink($document->getOrder(), $purposeMatches);
                    $item['payer_name'] = $payerName;
                    $item['document_name'] = $documentName;

                    $customerId = $document->getOrder()->getCustomerId();
                    if ($customerId) {
                        $customer = $this->loadCustomer($customerId);
                        $item['customer_increment_id'] = $this->display->getObjectLink($customer, $purposeMatches);
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
