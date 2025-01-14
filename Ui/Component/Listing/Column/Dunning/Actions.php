<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column\Dunning;

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
        array $data = [],
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
                            'banksync/dunning/edit',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Edit'),
                        'hidden' => false,
                    ];
                    $item[$name]['send'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/dunning/sendMail',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Send Mail'),
                        'hidden' => false,
                    ];
                    $item[$name]['print'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/dunning/printDunning',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Print'),
                        'hidden' => false,
                    ];
                    $item[$name]['archive'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/dunning/archive',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Archive'),
                        'hidden' => !empty($item['archived_at']),
                    ];
                    $item[$name]['unarchive'] = [
                        'href' => $this->urlBuilder->getUrl(
                            'banksync/dunning/unarchive',
                            ['id' => $item['entity_id']]
                        ),
                        'label' => __('Unarchive'),
                        'hidden' => empty($item['archived_at']),
                    ];
                }
            }
        }

        return $dataSource;
    }
}
