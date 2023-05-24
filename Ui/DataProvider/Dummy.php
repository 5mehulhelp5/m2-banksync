<?php

namespace Ibertrand\BankSync\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

class Dummy extends AbstractDataProvider
{
    public function addFilter(Filter $filter)
    {
    }

    public function getData()
    {
        return [];
    }

    public function getMeta()
    {
        return [];
    }
}
