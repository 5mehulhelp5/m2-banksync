<?php

namespace Ibertrand\BankSync\Setup\Patch\Data;

use Exception;
use Ibertrand\BankSync\Model\Dunning;
use Ibertrand\BankSync\Model\DunningRepository;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory;
use Ibertrand\BankSync\Setup\Patch\Schema\IsBanksynced as IsBanksyncedSchemaPatch;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class DunningIsPaid implements DataPatchInterface, PatchRevertableInterface
{
    private CollectionFactory $dunningCollectionFactory;
    private DunningRepository $dunningRepository;


    /**
     * @param CollectionFactory $dunningCollectionFactory
     * @param DunningRepository $dunningRepository
     */
    public function __construct(
        CollectionFactory $dunningCollectionFactory,
        DunningRepository $dunningRepository,
    ) {
        $this->dunningCollectionFactory = $dunningCollectionFactory;
        $this->dunningRepository = $dunningRepository;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [
            IsBanksyncedSchemaPatch::class,
        ];
    }

    /**
     * @return array|string[]
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @return void
     */
    public function apply()
    {
        $dunnings = $this->dunningCollectionFactory->create();
        foreach ($dunnings as $dunning) {
            /** @var Dunning $dunning */
            try {
                if ($dunning->updatePaidStatus()) {
                    $this->dunningRepository->save($dunning);
                }
            } catch (Exception $e) {
                echo $e->getMessage();
            }
        }
    }

    /**
     * @return void
     */
    public function revert(): void
    {
        // No revert
    }
}
