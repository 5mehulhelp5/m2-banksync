<?php

namespace Ibertrand\BankSync\Model\Config\Source;

use Ibertrand\BankSync\Helper\Dunning;
use Magento\Framework\Data\OptionSourceInterface;

class DunningTypes implements OptionSourceInterface
{
    protected Dunning $dunningHelper;

    /**
     * @param Dunning $dunningHelper
     */
    public function __construct(Dunning $dunningHelper)
    {
        $this->dunningHelper = $dunningHelper;
    }

    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        $storeId = 0;
        return array_map(
            fn ($type) => ['value' => $type, 'label' => $this->dunningHelper->getTypeLabel($type, $storeId)],
            $this->dunningHelper->getEnabledDunningTypes($storeId),
        );
    }

    /**
     * @return string[]
     */
    public function toArray(): array
    {
        $storeId = 0;
        $result = [];
        foreach ($this->dunningHelper->getEnabledDunningTypes(0) as $type) {
            $result[$type] = $this->dunningHelper->getTypeLabel($type, $storeId);
        }
        return $result;
    }
}
