<?php

namespace Ibertrand\BankSync\Cron;

use Exception;
use Ibertrand\BankSync\Helper\Data;
use Ibertrand\BankSync\Service\Matcher;
use Magento\Cron\Model\Schedule;
use Psr\Log\LoggerInterface;

class RunMatching
{
    protected LoggerInterface $logger;
    protected Matcher $matcher;
    private Data $helper;

    public function __construct(
        LoggerInterface $logger,
        Matcher         $matcher,
        Data            $helper
    ) {
        $this->logger = $logger;
        $this->matcher = $matcher;
        $this->helper = $helper;
    }

    /**
     * @param Schedule $schedule
     *
     * @return void
     */
    public function execute(Schedule $schedule): void
    {
        if (!$this->helper->isEnabled()) {
            $schedule->setMessages("BankSync is disabled");
            return;
        }

        if (!$this->helper->isAsyncMatching()) {
            $schedule->setMessages("Async matching is disabled");
            return;
        }

        try {
            $this->matcher->matchNewTransactions();
        } catch (Exception $e) {
            $this->logger->error($e);
        }
    }
}
