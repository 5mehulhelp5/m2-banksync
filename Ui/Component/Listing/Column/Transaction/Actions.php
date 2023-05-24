<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column\Transaction;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    protected UrlInterface $urlBuilder;

    public function __construct(
        ContextInterface   $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface       $urlBuilder,
        array              $components = [],
        array              $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        $name = $this->getData('name');
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['entity_id'])) {
                    $item[$name]['details'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/transaction/unbook',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('⤺ Undo'),
                        'hidden' => false,
                    ];
                }
            }
        }

        return $dataSource;
    }
}
