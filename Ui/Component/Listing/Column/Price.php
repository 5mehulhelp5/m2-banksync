<?php

namespace Ibertrand\BankSync\Ui\Component\Listing\Column;

use Magento\Directory\Model\Currency;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class Price extends \Magento\Sales\Ui\Component\Listing\Column\Price
{
    private StoreManagerInterface $storeManager;
    private Currency $currency;

    public function __construct(
        ContextInterface       $context,
        UiComponentFactory     $uiComponentFactory,
        PriceCurrencyInterface $priceFormatter,
        StoreManagerInterface  $storeManager,
        Currency               $currency,
        array                  $components = [],
        array                  $data = [],
    ) {
        parent::__construct(
            $context,
            $uiComponentFactory,
            $priceFormatter,
            $components,
            $data,
            $currency,
            $storeManager
        );
        $this->storeManager = $storeManager;
        $this->currency = $currency;
    }

    /**
     *
     * Changed: Don't crash on a null column value
     *
     * @param array $dataSource
     *
     * @return array
     * @throws NoSuchEntityException
     */
    public function prepareDataSource(array $dataSource)
    {
        $name = $this->getData('name');
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (!isset($item[$name])) {
                    continue;
                }
                $currencyCode = $item['base_currency_code'] ?? null;

                if (!$currencyCode) {
                    $itemStoreId = $item['store_id'] ?? '';
                    $storeId = $itemStoreId && is_numeric($itemStoreId) ? $itemStoreId :
                        $this->context->getFilterParam('store_id', Store::DEFAULT_STORE_ID);
                    $store = $this->storeManager->getStore($storeId);
                    $currencyCode = $store->getBaseCurrency()->getCurrencyCode();
                }
                $basePurchaseCurrency = $this->currency->load($currencyCode);
                $item[$name] = $basePurchaseCurrency->format($item[$name], [], false);
            }
        }

        return $dataSource;
    }
}
