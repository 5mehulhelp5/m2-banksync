<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column\CsvFormat;

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
        array              $data = [],
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
                    $item[$name]['edit'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/csvformat/edit',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Edit'),
                        'hidden' => false,
                    ];
                    $item[$name]['delete'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/csvformat/delete',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Delete'),
                        'hidden' => false,
                    ];
                }
            }
        }

        return $dataSource;
    }
}
