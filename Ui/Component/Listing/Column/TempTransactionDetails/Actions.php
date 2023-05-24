<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column\TempTransactionDetails;

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
                    $item[$name]['book'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/temptransaction/book',
                            ['id' => $item['transaction_id'], 'document_id' => $item['entity_id']]
                        ),
                        'label' => __('✓ Book'),
                        'hidden' => false,
                    ];
                }
            }
        }

        return $dataSource;
    }
}
