<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\CsvFormat;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\CsvFormatFactory;
use Ibertrand\BankSync\Model\CsvFormatRepository;
use Magento\Backend\App\Action;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\NoSuchEntityException;

class Save extends Action
{
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_csv_format';
    protected Logger $logger;
    private CsvFormatRepository $csvFormatRepository;
    private CsvFormatFactory $csvFormatFactory;

    public function __construct(
        Action\Context      $context,
        CsvFormatRepository $csvFormatRepository,
        CsvFormatFactory    $csvFormatFactory,
        Logger              $logger,
    ) {
        parent::__construct($context);
        $this->csvFormatRepository = $csvFormatRepository;
        $this->csvFormatFactory = $csvFormatFactory;
        $this->logger = $logger;
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $id = $this->getRequest()->getParam('entity_id');

        $encoding = $this->getRequest()->getParam('encoding');
        if (!in_array($encoding, mb_list_encodings())) {
            $this->messageManager->addErrorMessage(__('Invalid encoding. Valid encodings: %1', implode(', ', mb_list_encodings())));
            return $this->resultRedirectFactory->create()->setPath('*/*/edit', ['id' => $id]);
        }

        try {
            $format = !empty($id)
                ? $this->csvFormatRepository->getById($id)
                : $this->csvFormatFactory->create();

            $format->setName($this->getRequest()->getParam('name'));
            $format->setHasHeader(!empty($this->getRequest()->getParam('has_header')));
            $format->setDelimiter($this->getRequest()->getParam('delimiter'));
            $format->setEnclosure($this->getRequest()->getParam('enclosure'));
            $format->setIgnoreLeadingLines($this->getRequest()->getParam('ignore_leading_lines'));
            $format->setIgnoreTailingLines($this->getRequest()->getParam('ignore_tailing_lines'));
            $format->setIgnoreInvalidLines(!empty($this->getRequest()->getParam('ignore_invalid_lines')));
            $format->setThousandsSeparator($this->getRequest()->getParam('thousands_separator'));
            $format->setDecimalSeparator($this->getRequest()->getParam('decimal_separator'));
            $format->setDateFormat($this->getRequest()->getParam('date_format'));
            $format->setEncoding($this->getRequest()->getParam('encoding'));

            foreach ($format::COLUMNS as $column) {
                $format->setData($column . '_column', $this->getRequest()->getParam($column . '_column'));
                $format->setData($column . '_regex', $this->getRequest()->getParam($column . '_regex'));
            }

            $this->csvFormatRepository->save($format);

            $this->messageManager->addSuccessMessage(__('Format saved.'));
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Format not found.'));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('An error occurred while saving the format.'));
            $this->logger->error($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
