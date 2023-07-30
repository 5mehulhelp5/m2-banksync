<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\TempTransaction;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory;
use Ibertrand\BankSync\Service\Booker;
use Ibertrand\BankSync\Service\Matcher;
use Magento\Backend\App\Action;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;

class MassBook extends Action
{
    protected Logger $logger;
    protected Matcher $matcher;
    protected Booker $booker;
    protected Filter $filter;
    protected CollectionFactory $collectionFactory;
    protected Config $config;

    public function __construct(
        Action\Context    $context,
        Filter            $filter,
        CollectionFactory $collectionFactory,
        Matcher           $matcher,
        Booker            $booker,
        Logger            $logger,
        Config            $config,
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->matcher = $matcher;
        $this->booker = $booker;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * @return int[]|null
     * @throws LocalizedException
     */
    protected function getIds(): array|null
    {
        return $this->filter->getCollection($this->collectionFactory->create())->getAllIds();
    }

    /**
     * @return ResponseInterface|Redirect|ResultInterface
     * @throws LocalizedException
     */
    public function execute()
    {
        $ids = $this->getIds();
        # When IDs are selected, don't check the threshold (manual action).
        # Otherwise, only autobook if the threshold is reached.
        $minThreshold = empty($ids) ? null : 0;
        $results = $this->booker->autoBook($ids, $minThreshold);
        $successCount = count($results['success']);
        $errorCount = count($results['error']);

        if ($errorCount && !$this->config->isAsyncMatching()) {
            try {
                $this->matcher->matchTransactions($results['error']);
            } catch (Exception $e) {
                $this->logger->error($e);
                $this->messageManager->addErrorMessage(__("Some transactions could not be recalculated."));
            }
        }

        if ($successCount > 0 && $errorCount == 0) {
            $msg = __("%1 transactions have been booked successfully.", $successCount);
            $this->messageManager->addSuccessMessage($msg);
        } elseif ($successCount > 0 && $errorCount > 0) {
            $msg = __("%1 transactions have been booked successfully, %2 have failed.", $successCount, $errorCount);
            $this->messageManager->addWarningMessage($msg);
        } else {
            $msg = __("All (%1) transactions have failed.", $errorCount);
            $this->messageManager->addErrorMessage($msg);
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Ibertrand_BankSync::book');
    }
}
