<?php

namespace Ibertrand\BankSync\Setup\Patch\Data;

use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory;
use Ibertrand\BankSync\Model\Transaction;
use Ibertrand\BankSync\Setup\Patch\Schema\IsBanksynced as IsBanksyncedSchemaPatch;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;

class IsBanksynced implements DataPatchInterface, PatchRevertableInterface
{
    private CreditmemoRepositoryInterface $creditmemoRepository;
    private CollectionFactory $transactionCollectionFactory;
    private InvoiceRepositoryInterface $invoiceRepository;


    public function __construct(
        CollectionFactory             $transactionCollectionFactory,
        InvoiceRepositoryInterface    $invoiceRepository,
        CreditmemoRepositoryInterface $creditmemoRepository,
    ) {
        $this->transactionCollectionFactory = $transactionCollectionFactory;
        $this->invoiceRepository = $invoiceRepository;
        $this->creditmemoRepository = $creditmemoRepository;
    }

    public static function getDependencies(): array
    {
        return [
            IsBanksyncedSchemaPatch::class,
        ];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply()
    {
        foreach ($this->transactionCollectionFactory->create() as $transaction) {
            /** @var Transaction $transaction */

            $repository = $transaction->getDocumentType() === 'invoice'
                ? $this->invoiceRepository
                : $this->creditmemoRepository;

            $document = $repository->get($transaction->getDocumentId());
            $document->setIsBanksynced(1);
            $document->setHasDataChanges(true);
            $repository->save($document);
        }
    }

    public function revert(): void
    {
        // No revert
    }
}
