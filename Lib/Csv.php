<?php

namespace Ibertrand\BankSync\Lib;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Magento\Framework\File\Csv as CoreCsv;
use Magento\Framework\Filesystem\Driver\File;
use ValueError;

class Csv extends CoreCsv
{
    protected bool $hasHeaders;
    protected Logger $logger;
    protected int $ignoreLeadingLines = 0;
    protected int $ignoreTailingLines = 0;
    protected bool $ignoreInvalidLines = false;
    protected string $encoding = 'UTF-8';

    public function __construct(File $file, Logger $logger)
    {
        parent::__construct($file);
        $this->logger = $logger;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function setHasHeaders(bool $value): static
    {
        $this->hasHeaders = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setIgnoreLeadingLines(int $value): static
    {
        $this->ignoreLeadingLines = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setIgnoreTailingLines(int $value): static
    {
        $this->ignoreTailingLines = $value;
        return $this;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function setIgnoreInvalidLines(bool $value): static
    {
        $this->ignoreInvalidLines = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setEncoding(string $value): static
    {
        $this->encoding = $value;
        return $this;
    }

    /**
     * Get data from CSV file and return data as array
     *
     * @param string $file
     *
     * @return array
     * @throws Exception
     */
    public function getData($file)
    {
        $contents = $this->file->fileGetContents($file);

        if (!in_array($this->encoding, mb_list_encodings())) {
            throw new Exception('Invalid CSV file encoding: ' . $this->encoding);
        } elseif ($this->encoding !== 'UTF-8') {
            $contents = mb_convert_encoding($contents, 'UTF-8', $this->encoding);

            if ($contents === false) {
                throw new Exception('Encoding the file to UTF-8 failed');
            }
        }

        // Remove BOM if present
        if (substr($contents, 0, 3) === pack('CCC', 0xef, 0xbb, 0xbf)) {
            $contents = substr($contents, 3);
        }

        // Write to temp file, read it with the base CSV class and delete the temp file
        $tempFilename = tempnam(sys_get_temp_dir(), 'csv');
        $this->file->filePutContents($tempFilename, $contents);
        $data = parent::getData($tempFilename);
        $this->file->deleteFile($tempFilename);

        if ($this->ignoreLeadingLines > 0) {
            $data = array_slice($data, $this->ignoreLeadingLines);
        }
        if ($this->ignoreTailingLines > 0) {
            $data = array_slice($data, 0, -$this->ignoreTailingLines);
        }

        if ($this->hasHeaders) {
            $header = array_shift($data);
            $result = [];
            foreach ($data as $row) {
                if (count($row) === 1 && $row[0] === null) {
                    // Skip empty lines
                    continue;
                }
                try {
                    $result[] = array_combine($header, $row);
                } catch (ValueError) {
                    $this->logger->notice('Header: ' . var_export($header, true));
                    $this->logger->notice('Row: ' . var_export($row, true));
                    $this->logger->error(
                        'Invalid line in CSV file (different number of cells compared to header): '
                        . var_export($row, true)
                    );

                    if (!$this->ignoreInvalidLines) {
                        throw new Exception('Invalid line in CSV file (different number of cells compared to header)');
                    }
                    continue;
                }
            }
            return $result;
        }

        return $data;
    }
}
