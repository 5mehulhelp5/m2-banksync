<?php

namespace Ibertrand\BankSync\Model\Pdf;

use Ibertrand\BankSync\Model\ResourceModel\Dunning\Collection as DunningCollection;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Payment\Helper\Data;
use Magento\Sales\Model\Order\Address\Renderer;
use Magento\Sales\Model\Order\Pdf\AbstractPdf;
use Magento\Sales\Model\Order\Pdf\Config;
use Magento\Sales\Model\Order\Pdf\Invoice;
use Magento\Sales\Model\Order\Pdf\ItemsFactory;
use Magento\Sales\Model\Order\Pdf\Total\Factory;
use Magento\Sales\Model\RtlTextHandler;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zend_Pdf;
use Zend_Pdf_Exception;
use Zend_Pdf_Style;

class Dunning extends AbstractPdf
{

    protected StoreManagerInterface $_storeManager;
    protected Emulation $appEmulation;

    public function __construct(
        Data                  $paymentData,
        StringUtils           $string,
        ScopeConfigInterface  $scopeConfig,
        Filesystem            $filesystem,
        Config                $pdfConfig,
        Factory               $pdfTotalFactory,
        ItemsFactory          $pdfItemsFactory,
        TimezoneInterface     $localeDate,
        StateInterface        $inlineTranslation,
        Renderer              $addressRenderer,
        Emulation             $appEmulation,
        StoreManagerInterface $storeManager,
        array                 $data = [],
        Database              $fileStorageDatabase = null,
        ?RtlTextHandler       $rtlTextHandler = null,
    ) {
        $this->_storeManager = $storeManager;
        $this->appEmulation = $appEmulation;
        parent::__construct(
            $paymentData,
            $string,
            $scopeConfig,
            $filesystem,
            $pdfConfig,
            $pdfTotalFactory,
            $pdfItemsFactory,
            $localeDate,
            $inlineTranslation,
            $addressRenderer,
            $data,
            $fileStorageDatabase,
            $rtlTextHandler
        );
    }

    /**
     * Draw header for item table
     *
     * @param \Zend_Pdf_Page $page
     * @return void
     */
    protected function _drawHeader(\Zend_Pdf_Page $page)
    {
        /* Add table head */
        $this->_setFontRegular($page, 10);
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(25, $this->y, 570, $this->y - 15);
        $this->y -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));

        //columns headers
        $lines[0][] = ['text' => __('Products'), 'feed' => 35];

        $lines[0][] = ['text' => __('SKU'), 'feed' => 290, 'align' => 'right'];

        $lines[0][] = ['text' => __('Qty'), 'feed' => 435, 'align' => 'right'];

        $lines[0][] = ['text' => __('Price'), 'feed' => 360, 'align' => 'right'];

        $lines[0][] = ['text' => __('Tax'), 'feed' => 495, 'align' => 'right'];

        $lines[0][] = ['text' => __('Subtotal'), 'feed' => 565, 'align' => 'right'];

        $lineBlock = ['lines' => $lines, 'height' => 5];

        $this->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->y -= 20;
    }

    /**
     * Return PDF document
     *
     * Copied from Magento\Sales\Model\Order\Pdf\Invoice::getPdf()
     * Changes (apart from variable names):
     * - new label for insertDocumentNumber()
     *
     * @param \Ibertrand\BankSync\Model\Dunning[]|DunningCollection $dunnings
     * @return Zend_Pdf
     * @throws Zend_Pdf_Exception
     *
     * @see Invoice::getPdf()
     */
    public function getPdf($dunnings = [])
    {
        $this->_beforeGetPdf();
        $this->_initRenderer('invoice');

        $pdf = new Zend_Pdf();
        $this->_setPdf($pdf);
        $style = new Zend_Pdf_Style();
        $this->_setFontBold($style, 10);

        foreach ($dunnings as $dunning) {
            if ($dunning->getStoreId()) {
                $this->appEmulation->startEnvironmentEmulation(
                    $dunning->getStoreId(),
                    Area::AREA_FRONTEND,
                    true
                );
                $this->_storeManager->setCurrentStore($dunning->getStoreId());
            }
            $page = $this->newPage();
            $order = $dunning->getOrder();
            /* Add image */
            $this->insertLogo($page, $dunning->getStore());
            /* Add address */
            $this->insertAddress($page, $dunning->getStore());
            /* Add head */
            $this->insertOrder(
                $page,
                $order,
                $this->_scopeConfig->isSetFlag(
                    self::XML_PATH_SALES_PDF_INVOICE_PUT_ORDER_ID,
                    ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                )
            );
            /* Add document text and number */
            $this->insertDocumentNumber($page, $dunning->getLabel() . $dunning->getIncrementId());
            /* Add table */
            $this->_drawHeader($page);
            /* Add body */
            foreach ($dunning->getInvoice()->getAllItems() as $item) {
                if ($item->getOrderItem()->getParentItem()) {
                    continue;
                }
                /* Draw item */
                $this->_drawItem($item, $page, $order);
                $page = end($pdf->pages);
            }
            /* Add totals */
            $this->insertTotals($page, $dunning);
            if ($dunning->getStoreId()) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }
        $this->_afterGetPdf();
        return $pdf;
    }

}
