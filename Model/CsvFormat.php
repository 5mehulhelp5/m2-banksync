<?php

namespace Ibertrand\BankSync\Model;

use DateTime;
use Exception;
use Ibertrand\BankSync\Lib\Csv;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\CsvFormat as ResourceModel;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Class CsvFormat
 *
 * @method string getName()
 * @method setName(string $value)
 * @method string getEncoding()
 * @method setEncoding(string $value)
 * @method bool getHasHeader()
 * @method setHasHeader(bool $value)
 * @method string getDelimiter()
 * @method setDelimiter(string $value)
 * @method string getEnclosure()
 * @method setEnclosure(string $value)
 * @method string getThousandsSeparator()
 * @method setThousandsSeparator(string $value)
 * @method string getDecimalSeparator()
 * @method setDecimalSeparator(string $value)
 * @method string getDateFormat()
 * @method setDateFormat(string $value)
 * @method string getAmountColumn()
 * @method setAmountColumn(string $value)
 * @method string getAmountRegex()
 * @method setAmountRegex(string $value)
 * @method string getPurposeColumn()
 * @method setPurposeColumn(string $value)
 * @method string getPurposeRegex()
 * @method setPurposeRegex(string $value)
 * @method string getPayerNameColumn()
 * @method setPayerNameColumn(string $value)
 * @method string getPayerNameRegex()
 * @method setPayerNameRegex(string $value)
 * @method string getDateColumn()
 * @method setDateColumn(string $value)
 * @method string getDateRegex()
 * @method setDateRegex(string $value)
 * @method int getIgnoreLeadingLines()
 * @method setIgnoreLeadingLines(int $value)
 * @method int getIgnoreTailingLines()
 * @method setIgnoreTailingLines(int $value)
 * @method bool getIgnoreInvalidLines()
 * @method setIgnoreInvalidLines(bool $value)
 *
 */
class CsvFormat extends AbstractModel
{
    const COLUMNS = [
        'amount',
        'purpose',
        'payer_name',
        'date',
    ];

    /**
     * @var string
     */
    protected $_eventPrefix = 'banksync_csv_format_model';
    protected Csv $csvProcessor;
    protected Logger $logger;

    /**
     * @param Context               $context
     * @param Registry              $registry
     * @param Csv                   $csvProcessor
     * @param Logger                $logger
     * @param AbstractResource|null $resource
     * @param AbstractDb|null       $resourceCollection
     * @param array                 $data
     */
    public function __construct(
        Context          $context,
        Registry         $registry,
        Csv              $csvProcessor,
        Logger           $logger,
        AbstractResource $resource = null,
        AbstractDb       $resourceCollection = null,
        array            $data = [],
    ) {
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
        $this->csvProcessor = $csvProcessor;
        $this->logger = $logger;
    }

    /**
     * @param string $filename
     * @return array
     * @throws NoSuchEntityException
     * @throws Exception
     */
    public function loadFile(string $filename): array
    {
        return $this->parseCsvContent(
            $this->loadCsvContent($filename)
        );
    }

    /**
     * @param array $rows
     * @return array
     *
     * @throws NoSuchEntityException
     * @throws Exception
     */
    protected function parseCsvContent(array $rows): array
    {
        $result = [];

        $columns = [];
        foreach (self::COLUMNS as $COLUMN) {
            $columns[$COLUMN] = $this->getData($COLUMN . '_column');
        }

        foreach ($rows as $row) {
            $rowValues = [];
            foreach ($columns as $name => $csvName) {
                $csvNames = explode($this->getDelimiter(), $csvName);
                $values = [];
                foreach ($csvNames as $_csvName) {
                    $values[] = $this-> getValue($row, trim($_csvName), $this->getData($name . '_regex'));
                }
                $rowValues[$name] = trim(implode(' / ', $values), ' /');
            }
            if (empty(array_filter($rowValues))) {
                continue;
            }
            $values = array_combine(self::COLUMNS, $rowValues);
            $values['amount'] = $this->parseAmount($values['amount']);
            $values['transaction_date'] = $this->parseDate($values['date']);

            // Not used by this module, but useful for individual implementations, e.g. to filter rows during import
            $values['_original_row'] = $row;

            $result[] = $values;
        }
        return $result;
    }

    /**
     * @param array  $row
     * @param string $column
     * @param string $regexPattern
     * @return string
     * @throws Exception
     */
    public function getValue(array $row, string $column, string $regexPattern): string
    {
        // Don't check with empty() because 0 is a valid value
        if ($column === '') {
            return '';
        }

        try {
            $columnContent = trim($row[$column]) ?? '';
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error('Column: ' . $column);
            $this->logger->error('Row: ' . json_encode($row));
            throw $e;
        }

        if (empty($regexPattern)) {
            return $columnContent;
        }

        if (!preg_match($regexPattern, $columnContent, $matches)) {
            return '';
        }

        // return a capture group if the regex pattern contains one, otherwise return the whole match
        return trim($matches[1]) ?? trim($matches[0]) ?? '';
    }

    /**
     * @param string $amount
     * @return string
     * @throws Exception
     */
    private function parseAmount(string $amount): string
    {
        $originalValue = $amount;
        if ($amount === '') {
            return '0';
        }
        $value = str_replace($this->getThousandsSeparator(), '', $amount);
        $value = str_replace($this->getDecimalSeparator(), '.', $value);
        $value = str_replace(' ', '', $value);

        $value = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $value = filter_var($value, FILTER_VALIDATE_FLOAT);

        if ($value === false) {
            throw new Exception('Invalid amount format: ' . $originalValue);
        }

        return number_format((float)$value, 2, '.', ''); // return as string to avoid floating point errors
    }

    /**
     * @param string $format
     * @return bool
     */
    private function dateFormateContainsTimePart(string $format): bool
    {
        // Crude detection, but should work in 99% of use cases
        return preg_match('/[His]/', $format) === 1;
    }

    /**
     * @param string $date
     * @return string
     * @throws Exception
     */
    private function parseDate(string $date): string
    {
        $format = $this->getDateFormat();
        $parsedDate = DateTime::createFromFormat($format, $date);
        if (!$parsedDate) {
            throw new Exception('Invalid date format');
        }
        // If the format doesn't contain Time component, then set time to 00:00:00
        if (!$this->dateFormateContainsTimePart($format)) {
            $parsedDate->setTime(0, 0);
        }
        return $parsedDate->format('Y-m-d H:i:s');
    }

    /**
     * @param string $filename
     * @return array
     * @throws Exception
     */
    protected function loadCsvContent(string $filename): array
    {
        return $this->getCsvProcessor()->getData($filename);
    }

    /**
     * @return Csv
     */
    protected function getCsvProcessor(): Csv
    {
        return $this->csvProcessor
            ->setHasHeaders($this->getHasHeader())
            ->setDelimiter($this->getDelimiter())
            ->setEnclosure($this->getEnclosure())
            ->setIgnoreLeadingLines($this->getIgnoreLeadingLines())
            ->setIgnoreTailingLines($this->getIgnoreTailingLines())
            ->setIgnoreInvalidLines($this->getIgnoreInvalidLines())
            ->setEncoding($this->getEncoding() ?? 'UTF-8');
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
}
