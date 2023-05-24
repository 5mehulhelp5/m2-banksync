<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column\TempTransaction;

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
                    if (!empty($item['allow_book'])) {
                        $item[$name]['autobook'] = [
                            'href' => $this->urlBuilder->getUrl(
                                'banksync/temptransaction/autoBook',
                                ['id' => $item['entity_id']]
                            ),
                            'label' => __('✓ Book'),
                            'hidden' => false,
                        ];
                    }

                    $item[$name]['search'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/temptransaction/search',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('🔍 Search'),
                        'hidden' => false,
                    ];

                    if ($item['document_count'] > 1) {
                        $item[$name]['details'] = [
                            'href' => $this->urlBuilder->getUrl(
                                'banksync/temptransaction/details',
                                ['id' => $item['entity_id']]
                            ),
                            'label' => __('≡ Details'),
                            'hidden' => false,
                        ];
                    }

                    $item[$name]['delete'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/temptransaction/delete',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('✖ Delete'),
                        'hidden' => false,
                        'confirm' => [
                            'title' => __('Delete "%1"', $item['entity_id']),
                            'message' => __(
                                'Are you sure you want to delete the record "%1"',
                                $item['entity_id']
                            ),
                        ],
                    ];
                }
            }
        }

        return $dataSource;
    }
}
