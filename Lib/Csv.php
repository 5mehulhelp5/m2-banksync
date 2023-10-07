<?php

namespace Ibertrand\BankSync\Lib;

use Exception;
use Magento\Framework\File\Csv as CoreCsv;

class Csv extends CoreCsv
{
    private bool $_hasHeaders;

    public function setHasHeaders(bool $value): static
    {
        $this->_hasHeaders = $value;
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
        $tempFilename = tempnam(sys_get_temp_dir(), 'csv');
        $contents = $this->file->fileGetContents($file);

        // Remove UTF8 BOM if present
        if (substr($contents, 0, 3) == pack('CCC', 0xef, 0xbb, 0xbf)) {
            $contents = substr($contents, 3);
        }

        $this->file->filePutContents($tempFilename, $contents);

        $data = parent::getData($tempFilename);

        $this->file->deleteFile($tempFilename);

        if ($this->_hasHeaders) {
            $header = array_shift($data);
            return array_map(function ($row) use ($header) {
                return array_combine($header, $row);
            }, $data);
        }

        return $data;
    }
}
