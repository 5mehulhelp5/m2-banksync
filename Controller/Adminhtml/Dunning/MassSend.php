<?php

namespace Ibertrand\BankSync\Controller\Adminhtml\Dunning;

use Exception;
use Ibertrand\BankSync\Logger\Logger;
use Ibertrand\BankSync\Model\Dunning;
use Ibertrand\BankSync\Model\DunningRepository;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\Collection as DunningCollection;
use Ibertrand\BankSync\Model\ResourceModel\Dunning\CollectionFactory as DunningCollectionFactory;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Ui\Component\MassAction\Filter;

class MassSend extends Action
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Ibertrand_BankSync::sub_menu_dunnings';

    protected string $redirectUrl = 'banksync/dunning/index';
    protected FileFactory $fileFactory;
    protected DateTime $dateTime;
    protected Filter $filter;
    protected DunningCollectionFactory $collectionFactory;
    protected DunningRepository $dunningRepository;
    protected Logger $logger;

    /**
     * @param Context                  $context
     * @param DateTime                 $dateTime
     * @param FileFactory              $fileFactory
     * @param Filter                   $filter
     * @param DunningCollectionFactory $collectionFactory
     * @param DunningRepository        $dunningRepository
     * @param Logger                   $logger
     */
    public function __construct(
        Context                  $context,
        DateTime                 $dateTime,
        FileFactory              $fileFactory,
        Filter                   $filter,
        DunningCollectionFactory $collectionFactory,
        DunningRepository        $dunningRepository,
        Logger                   $logger,
    ) {
        $this->fileFactory = $fileFactory;
        $this->dateTime = $dateTime;
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->dunningRepository = $dunningRepository;
        $this->logger = $logger;
        parent::__construct($context);
    }

    /**
     * @return DunningCollection
     */
    protected function getCollection(): DunningCollection
    {
        return $this->collectionFactory->create();
    }

    /**
     * Save collection items to pdf invoices
     *
     * @param AbstractCollection $collection
     *
     * @return Redirect
     * @throws InputException
     * @throws NoSuchEntityException
     */
    public function massAction(AbstractCollection $collection): Redirect
    {
        /** @var Dunning[] $dunnings */
        $dunnings = $collection->getItems();
        $dunnings = array_filter($dunnings, fn ($dunning) => !$dunning->getInvoiceIsBlocked());
        if (count($dunnings) < $collection->getSize()) {
            $count = $collection->getSize() - count($dunnings);
            $this->messageManager->addErrorMessage($count . ' dunning(s) could not be sent because the invoice is blocked.');
        }

        $failed = 0;
        $success = 0;
        if (count($dunnings) == 0) {
            $this->messageManager->addErrorMessage('No dunnings selected.');
        }
        foreach ($dunnings as $dunning) {
            /** @var Dunning $dunning */
            try {
                if ($dunning->sendMail()) {
                    $this->dunningRepository->save($dunning);
                    $success += 1;
                } else {
                    $failed += 1;
                }
            } catch (Exception $e) {
                $this->logger->error("Failed to send dunning: {$e->getMessage()}\n{$e->getTraceAsString()}");
                $failed += 1;
            }
        }
        if ($failed > 0 && $success > 0) {
            $this->messageManager->addErrorMessage($failed . ' dunning(s) could not be sent. ' . $success . ' dunning(s) sent.');
        } elseif ($failed > 0) {
            $this->messageManager->addErrorMessage($failed . ' dunning(s) could not be sent.');
        } elseif ($success > 0) {
            $this->messageManager->addSuccessMessage($success . ' dunning(s) sent.');
        }

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath($this->redirectUrl);
    }

    /**
     * Execute action
     *
     * @return Redirect|ResponseInterface
     * @throws Exception
     */
    public function execute()
    {
        try {
            /** @var AbstractCollection $collection */
            $collection = $this->filter->getCollection($this->getCollection());
            return $this->massAction($collection);
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            /** @var Redirect $resultRedirect */
            $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
            return $resultRedirect->setPath($this->redirectUrl);
        }
    }
}
