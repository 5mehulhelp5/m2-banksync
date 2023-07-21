<?php

namespace Ibertrand\BankSync\Setup\Patch\Schema;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Sales\Setup\SalesSetup;

class IsBanksynced implements DataPatchInterface, PatchRevertableInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private SalesSetup $salesSetup;


    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        SalesSetup               $salesSetup,
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->salesSetup = $salesSetup;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $this->salesSetup->addAttribute(
            'invoice',
            'is_banksynced',
            [
                'type' => 'int',
                'label' => 'Is banksynced',
                'comment' => 'Has transaction',
                'grid' => true,
            ]
        );

        $this->moduleDataSetup->endSetup();
    }

    public function revert(): void
    {
        // No revert
    }
}
