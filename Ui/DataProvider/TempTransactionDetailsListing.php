<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Ibertrand\BankSync\Helper\Data as BankSyncHelper;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence;
use Ibertrand\BankSync\Model\ResourceModel\MatchConfidence\CollectionFactory as MatchConfidenceCollectionFactory;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

class TempTransactionDetailsListing extends TempTransactionSearchDocumentListing
{
    private MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory;
    private MatchConfidence $matchConfidenceResource;

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
     * @param BankSyncHelper $helper
     * @param CustomerCollectionFactory $customerCollectionFactory
     * @param PriceHelper $priceHelper
     * @param MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory
     * @param MatchConfidence $matchConfidenceResource
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
        UrlInterface $urlBuilder,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        CreditmemoCollectionFactory $creditmemoCollectionFactory,
        TempTransactionRepository $tempTransactionRepository,
        OrderCollectionFactory $orderCollectionFactory,
        CustomerFactory $customerFactory,
        CustomerResource $customerResource,
        Http $request,
        BankSyncHelper $helper,
        CustomerCollectionFactory $customerCollectionFactory,
        PriceHelper $priceHelper,
        MatchConfidenceCollectionFactory $matchConfidenceCollectionFactory,
        MatchConfidence $matchConfidenceResource,
        array $meta = [],
        array $data = []
    ) {
        $this->matchConfidenceCollectionFactory = $matchConfidenceCollectionFactory;
        $this->matchConfidenceResource = $matchConfidenceResource;

        parent::__construct(
            $name,
            $primaryFieldName,
            $requestFieldName,
            $urlBuilder,
            $invoiceCollectionFactory,
            $creditmemoCollectionFactory,
            $tempTransactionRepository,
            $orderCollectionFactory,
            $customerFactory,
            $customerResource,
            $request,
            $helper,
            $customerCollectionFactory,
            $priceHelper,
            $meta,
            $data
        );
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
        $documentIds = $this->matchConfidenceCollectionFactory->create()
            ->addFieldToFilter('temp_transaction_id', $tempTransaction->getId())
            ->getColumnValues('document_id');

        $this->collection = $documentType
            ? $this->invoiceCollectionFactory->create()
            : $this->creditmemoCollectionFactory->create();

        $this->collection->addFieldToFilter('main_table.entity_id', ['in' => $documentIds]);

        $condition = $this->collection->getConnection()->quoteInto(
            'main_table.entity_id = t_mc.document_id AND t_mc.temp_transaction_id = ?',
            $tempTransaction->getId()
        );
        $this->collection->join(
            ['t_mc' => $this->matchConfidenceResource->getMainTable()],
            $condition,
            ['match_confidence' => 't_mc.confidence']
        );
    }

    /**
     * @return array
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getData()
    {
        $data = parent::getData();

        foreach ($data['items'] as &$item) {
            $confidence = $item['match_confidence'];
            $class = $confidence > 200 ? 'high' : "low";
            $item['match_confidence_text'] = "<div class='banksync-confidence-$class'>$confidence</div>";
        }

        return $data;
    }

    public function addOrder($field, $direction)
    {
        if ($field === 'match_confidence_text')
            $field = 'match_confidence';
        parent::addOrder($field, $direction);
    }
}
