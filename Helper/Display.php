<?php

namespace Ibertrand\BankSync\Helper;

use Magento\Customer\Model\Customer;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\DataObject;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class Display extends AbstractHelper
{

    private UrlInterface $urlBuilder;

    public function __construct(
        Context                         $context,
        UrlInterface $urlBuilder
    ) {
        parent::__construct($context);
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param Order $order
     * @return string
     */
    public function getCustomerNamesForListing(Order $order): string
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        return implode(
            '<br>',
            array_filter(array_unique([
                trim($order->getCustomerName() ?? ""),
                trim(($billing->getFirstname() ?? "") . ' ' . ($billing->getLastname() ?? "")),
                trim($billing->getCompany() ?? ""),
                trim(($shipping->getFirstname() ?? "") . ' ' . ($shipping->getLastname() ?? "")),
                trim($shipping->getCompany() ?? ""),
            ]))
        );
    }


    public function getObjectLink(DataObject $object, array $matchedTexts): string
    {
        if ($object instanceof Invoice) {
            $url = $this->urlBuilder->getUrl('sales/invoice/view', ['invoice_id' => $object->getId()]);
        } elseif ($object instanceof Creditmemo) {
            $url = $this->urlBuilder->getUrl('sales/creditmemo/view', ['creditmemo_id' => $object->getId()]);
        } elseif ($object instanceof Order) {
            $url = $this->urlBuilder->getUrl('sales/order/view', ['order_id' => $object->getId()]);
        } elseif ($object instanceof Customer) {
            $url = $this->urlBuilder->getUrl('customer/index/edit', ['id' => $object->getId()]);
        } else {
            return '';
        }
        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
        $incrementId = $object->getIncrementId();
        $cssClass = in_array($incrementId, array_keys($matchedTexts)) ? 'banksync-matched-text' : '';
        if ($cssClass == '' && str_ends_with($incrementId, '00')) {
            $incrementId = substr($incrementId, 0, -2);
            $cssClass = in_array($incrementId, array_keys($matchedTexts)) ? 'banksync-matched-text' : '';
        }
        return "<a class='$cssClass' href='$url'>$incrementId</a>";
    }
}
