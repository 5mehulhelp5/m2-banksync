<?php

namespace Ibertrand\BankSync\Helper;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\Dunning as DunningModel;
use Ibertrand\BankSync\Model\DunningFactory;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory as DunningCollectionFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

class Dunning extends AbstractHelper
{

    protected Config $config;
    protected DunningCollectionFactory $dunningCollectionFactory;
    protected DunningFactory $dunningFactory;
    protected InvoiceRepositoryInterface $invoiceRepository;
    private Logger $logger;
    private InvoiceCollectionFactory $invoiceCollectionFactory;

    public function __construct(
        Context                    $context,
        Config                     $config,
        DunningCollectionFactory   $dunningCollectionFactory,
        DunningFactory             $dunningFactory,
        InvoiceRepositoryInterface $invoiceRepository,
        InvoiceCollectionFactory   $invoiceCollectionFactory,
        Logger                     $logger,
    ) {
        $this->config = $config;
        $this->dunningCollectionFactory = $dunningCollectionFactory;
        $this->dunningFactory = $dunningFactory;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * Retrieves the list of supported dunning types.
     * If this list is extended, make sure the order is correct.
     *
     * @return array An array of supported dunning types.
     */
    public function getDunningTypes(): array
    {
        return [
            'reminder_1',
            'reminder_2',
            'dunning_1',
            'dunning_2',
        ];
    }

    /**
     * @param int $storeId
     * @return array
     */
    public function getEnabledDunningTypes(int $storeId): array
    {
        return array_filter($this->getDunningTypes(), fn ($type) => $this->isTypeEnabled($type, $storeId));
    }

    /**
     * @param string $type
     * @return bool
     */
    public function isTypeValid(string $type): bool
    {
        return in_array($type, $this->getDunningTypes());
    }

    /**
     * @param string $type
     * @return void
     * @throws Exception
     */
    public function checkValidType(string $type): void
    {
        if (!$this->isTypeValid($type)) {
            throw new Exception("Invalid dunning type: $type");
        }
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return string
     */
    public function getTypeLabel(string $type, int $storeId): string
    {
        return $this->config->getDunningTypeLabel($type, $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return bool
     */
    public function isTypeEnabled(string $type, int $storeId): bool
    {
        return $this->config->isDunningsEnabled($storeId) && $this->config->isDunningEnabled($type, $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return bool
     */
    public function typeUsesPdf(string $type, int $storeId): bool
    {
        return $this->config->isAttacheTypePdf($type, $storeId);
    }

    /**
     * Retrieves the delay in days for a given dunning type.
     *
     * @param string $type
     * @param int    $storeId
     * @return int Days
     */
    public function getTypeDelay(string $type, int $storeId): int
    {
        return $this->config->getDunningTypeDelay($type, $storeId);
    }

    /**
     * @param string $date
     * @return float
     */
    protected function getAgeInDays(string $date): float
    {
        return (time() - strtotime($date)) / 86400;
    }

    /**
     * @param Invoice $invoice
     * @return string|null
     */
    public function getDunningTypeToSend(Invoice $invoice): ?string
    {
        $storeId = $invoice->getStoreId();

        $ageInDays = $this->getAgeInDays($invoice->getCreatedAt());

        $existingDunnings = $this->dunningCollectionFactory->create()
            ->addFieldToFilter('invoice_id', $invoice->getId());

        $enabledDunningTypes = $this->getEnabledDunningTypes($storeId);
        foreach ($enabledDunningTypes as $type) {
            /** @var DunningModel $oldDunning */
            $oldDunning = $existingDunnings->getItemByColumnValue('dunning_type', $type);
            if ($oldDunning) {
                if (empty($oldDunning->getSentAt())) {
                    // If a dunning has been created but not sent, we don't create the next one
                    // to avoid creating all of them at once
                    return null;
                }
                // If a dunning has been sent, the next delay will be based on the date it was sent
                $ageInDays = $this->getAgeInDays($oldDunning->getSentAt());
                continue;
            }
            if ($ageInDays >= $this->getTypeDelay($type, $storeId)) {
                return $type;
            }
            // If the delay of this type is not reached, we don't need to check the next ones
            return null;
        }
        return null;
    }

    /**
     * @param Invoice $invoice
     * @param string  $type
     * @return DunningModel
     * @throws Exception
     */
    public function getDunning(Invoice $invoice, string $type): DunningModel
    {
        $this->checkValidType($type);

        $collection = $this->dunningCollectionFactory->create()
            ->addFieldToFilter('invoice_id', $invoice->getId())
            ->addFieldToFilter('dunning_type', $type);

        if ($collection->getSize() > 0) {
            /** @noinspection PhpIncompatibleReturnTypeInspection */
            return $collection->getFirstItem();
        }

        // If a dunning exists with a disabled type, it will be returned (so old dunnings can be loaded),
        // but we won't create a new if it doesn't exist
        if (!$this->isTypeEnabled($type, $invoice->getStoreId())) {
            throw new Exception('Dunning type is not enabled');
        }

        return $this->dunningFactory->create()
            ->setInvoiceId($invoice->getId())
            ->setDunningType($type);
    }

    /**
     * @param Invoice $invoice
     * @return DunningModel|null
     * @throws Exception
     */
    public function getDunningToSend(Invoice $invoice): ?DunningModel
    {
        $dunningTypeToSend = $this->getDunningTypeToSend($invoice);
        return !empty($dunningTypeToSend)
            ? $this->getDunning($invoice, $dunningTypeToSend)
            : null;
    }

    /**
     * @param string   $getDunningType
     * @param int|null $storeId
     * @return string
     */
    public function getEmailTemplate(string $getDunningType, int $storeId = null): string
    {
        return $this->config->getDunningEmailTemplate($getDunningType, $storeId);
    }

    /**
     * @param int $storeId
     * @return string
     */
    public function getSenderIdentity(int $storeId): string
    {
        return $this->config->getDunningSenderIdentity($storeId);
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isEnabled(int $storeId): bool
    {
        return $this->config->isDunningsEnabled($storeId);
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isAutoSendMailEnabled(int $storeId): bool
    {
        return $this->config->isAutoSendDunningsMailEnabled($storeId);
    }

    /**
     * @param int $storeId
     * @return int
     */
    protected function getMinDelay(int $storeId): int
    {
        $types = $this->getEnabledDunningTypes($storeId);
        if (empty($types)) {
            return 0;
        }
        return min(array_map(fn ($type) => $this->getTypeDelay($type, $storeId), $types));
    }

    /**
     * @param int $storeId
     * @return InvoiceCollection
     */
    public function getOpenInvoices(int $storeId): InvoiceCollection
    {
        $minCreationDate = $this->config->getStartDate();
        $latestCreationDate = date('Y-m-d H:i:s', time() - $this->getMinDelay($storeId) * 86400);
        $collection = $this->invoiceCollectionFactory->create()
            ->addFieldToFilter('main_table.created_at', ['gt' => $minCreationDate])
            ->addFieldToFilter('main_table.created_at', ['lt' => $latestCreationDate])
            ->addFieldToFilter('main_table.store_id', $storeId)
            ->addFieldToFilter('is_banksynced', ['eq' => 0]);

        $paymentMethods = $this->config->getPaymentMethods();
        if (!empty($paymentMethods)) {
            $collection->join(
                ['payment' => 'sales_order_payment'],
                'main_table.order_id = payment.parent_id',
                []
            )->addFieldToFilter('payment.method', ['in' => $this->config->getPaymentMethods()]);
        }

        $this->logger->info($collection->getSelectSql(true));
        return $collection;
    }

    public function getInvoiceDueDays(): int
    {
        return $this->config->getInvoiceDueDays();
    }

}
