<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Exception;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order\CreditmemoRepository;
use Magento\Sales\Model\Order\InvoiceRepository;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Psr\Log\LoggerInterface;

class TransactionListing extends AbstractDataProvider
{
    protected UrlInterface $urlBuilder;
    protected InvoiceRepository $invoiceRepository;
    protected CreditmemoRepository $creditmemoRepository;
    protected LoggerInterface $logger;

    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        UrlInterface $urlBuilder,
        InvoiceRepository $invoiceRepository,
        CreditmemoRepository $creditmemoRepository,
        LoggerInterface $logger,
        array $meta = [],
        array $data = [],
    ) {
        $this->collection = $collectionFactory->create();
        $this->urlBuilder = $urlBuilder;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
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
                $item['document_name'] = $document->getOrder()->getCustomerName();
                $item['document_amount'] = $document->getGrandTotal();
                $item['document_date'] = $document->getCreatedAt();
                $orderUrl = $this->urlBuilder->getUrl(
                    'sales/order/view',
                    ['order_id' => $document->getOrder()->getId()]
                );
                $item['order_increment_id'] = "<a href='$orderUrl'>{$document->getOrder()->getIncrementId()}</a>";
                $item['payment_method'] = $document->getOrder()->getPayment()->getMethodInstance()->getTitle();
            } catch (Exception $e) {
                $this->logger->error($e);
                $item['document'] = "[Not found]";
            }
        }

        return $data;
    }
}
