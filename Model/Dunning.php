<?php

namespace Ibertrand\BankSync\Model;

use Exception;
use Ibertrand\BankSync\Helper\Dunning as DunningHelper;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\Dunning as ResourceModel;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\MailException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\InvoiceRepository;

/**
 * Class Dunning
 *
 * @method int getEntityId()
 * @method $this setEntityId(int $id)
 * @method int getInvoiceId()
 * @method $this setInvoiceId(int $id)
 * @method string getDunningType()
 * @method $this setDunningType(string $type)
 * @method bool getIsPaid()
 * @method $this setIsPaid(bool $value)
 * @method string getSentAt()
 * @method $this setSentAt(string $date)
 * @method string getArchivedAt()
 * @method $this setArchivedAt(string $date)
 * @method string getComment()
 * @method $this setComment(string $date)
 * @method string getCreatedAt()
 * @method $this setCreatedAt(string $date)
 * @method string getUpdatedAt()
 * @method $this setUpdatedAt(string $date)
 */
class Dunning extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_dunning_model';
    protected DunningHelper $dunningHelper;
    protected InvoiceRepository $invoiceRepository;
    protected TransportBuilder $transportBuilder;
    protected Logger $logger;

    public function __construct(
        Context           $context,
        Registry          $registry,
        DunningHelper     $dunningHelper,
        InvoiceRepository $invoiceRepository,
        TransportBuilder  $transportBuilder,
        Logger            $logger,
        AbstractResource  $resource = null,
        AbstractDb        $resourceCollection = null,
        array             $data = [],
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        $this->dunningHelper = $dunningHelper;
        $this->invoiceRepository = $invoiceRepository;
        $this->transportBuilder = $transportBuilder;
        $this->logger = $logger;
    }

    /**
     * Initialize magento model.
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(ResourceModel::class);
    }

    /**
     * @return string
     * @throws InputException
     * @throws NoSuchEntityException
     */
    protected function getEmailTemplate(): string
    {
        return $this->dunningHelper->getEmailTemplate($this->getDunningType(), $this->getInvoice()->getStoreId());
    }

    /**
     * @return Invoice
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getInvoice(): Invoice
    {
        return $this->invoiceRepository->get($this->getInvoiceId());
    }

    /**
     * @return Order
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getOrder(): Order
    {
        return $this->getInvoice()->getOrder();
    }


    /**
     * @return int
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getStoreId(): int
    {
        return $this->getInvoice()->getStoreId();
    }

    /**
     * @return string
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getLabel(): string
    {
        return $this->dunningHelper->getTypeLabel($this->getDunningType(), $this->getStoreId());
    }

    /**
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function getInvoiceIsBlocked(): bool
    {
        /** @noinspection PhpUndefinedMethodInspection */
        return !empty($this->getInvoice()->getBanksyncDunningBlockedAt());
    }

    /**
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @throws MailException
     */
    public function sendMail(): bool
    {
        $invoice = $this->getInvoice();

        if ($this->getInvoiceIsBlocked()) {
            $this->logger->info('Dunning mail not sent because invoice is blocked');
            return false;
        }

        $order = $invoice->getOrder();
        $storeId = $invoice->getStoreId();
        $templateCode = $this->getEmailTemplate();
        $dueDays = $this->dunningHelper->getInvoiceDueDays();
        $invoiceDueDate = strtotime($invoice->getCreatedAt()) + $dueDays * 86400;
        $customerName = trim($order->getCustomerName());

        $this->logger->info('Sending dunning mail to ' . $order->getCustomerEmail());


        $emailTemplateVariables = [
            'invoice_id' => $invoice->getIncrementId(),
            'due_date' => date('d.m.Y', $invoiceDueDate),
            'store_name' => $invoice->getStore()->getFrontendName(),
            'customer_name' => $customerName,
        ];

        $transport = $this->transportBuilder->setTemplateIdentifier($templateCode)
            ->setTemplateOptions(['area' => 'frontend', 'store' => $storeId])
            ->addTo($order->getCustomerEmail(), $customerName)
            ->setFromByScope($this->dunningHelper->getSenderIdentity($storeId), $storeId)
            ->setTemplateVars($emailTemplateVariables)
            ->getTransport();

        try {
            $transport->sendMessage();
            $this->setSentAt(date('Y-m-d H:i:s'));
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to send dunning mail: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * @return bool
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function updatePaidStatus(): bool
    {
        $oldStatus = (int)$this->getIsPaid();
        /** @noinspection PhpUndefinedMethodInspection */
        $this->setIsPaid($this->getInvoice()->getIsBanksynced());
        return $oldStatus !== (int)$this->getIsPaid();
    }
}
