<?php

namespace Ibertrand\BankSync\Setup\Patch\Data;

use Ibertrand\BankSync\Model\CsvFormatFactory;
use Ibertrand\BankSync\Model\CsvFormatRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

class MigrateCsvFormat implements DataPatchInterface, PatchRevertableInterface
{
    private CsvFormatFactory $csvFormatFactory;
    private CsvFormatRepository $csvFormatRepository;
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param CsvFormatFactory     $csvFormatFactory
     * @param CsvFormatRepository  $csvFormatRepository
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        CsvFormatFactory     $csvFormatFactory,
        CsvFormatRepository  $csvFormatRepository,
        ScopeConfigInterface $scopeConfig,
    ) {
        $this->csvFormatFactory = $csvFormatFactory;
        $this->csvFormatRepository = $csvFormatRepository;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
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
     * @throws CouldNotSaveException
     */
    public function apply()
    {
        $format = $this->csvFormatFactory->create();
        $format->setName('Default');
        $format->setHasHeader(true);

        $format->setDelimiter($this->getConfig('general/delimiter'));
        $format->setEnclosure($this->getConfig('general/enclosure'));

        $format->setThousandsSeparator($this->getConfig('general/thousand_separator') ?? "");
        $format->setDecimalSeparator($this->getConfig('general/decimal_separator') ?? "");
        $format->setDateFormat("d.m.Y");

        $format->setAmountColumn($this->getConfig('fields/amount'));
        $format->setPurposeColumn($this->getConfig('fields/purpose'));
        $format->setPayerNameColumn($this->getConfig('fields/payer_name'));
        $format->setDateColumn($this->getConfig('fields/transaction_date'));

        $format->setAmountRegex("");
        $format->setPurposeRegex("");
        $format->setPayerNameRegex("");
        $format->setDateRegex("");

        $this->csvFormatRepository->save($format);
    }

    private function getConfig($key)
    {
        return $this->scopeConfig->getValue('banksync/csv_settings/' . $key);
    }

    /**
     * @return void
     */

    public function revert(): void
    {
        // No revert
    }
}
