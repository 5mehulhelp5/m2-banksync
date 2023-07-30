<?php

namespace Ibertrand\BankSync\Cron;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Service\Matcher;
use Magento\Cron\Model\Schedule;
use Psr\Log\LoggerInterface;

class RunMatching
{
    protected LoggerInterface $logger;
    protected Matcher $matcher;
    protected Config $config;

    public function __construct(
        LoggerInterface $logger,
        Matcher         $matcher,
        Config          $config,
    ) {
        $this->logger = $logger;
        $this->matcher = $matcher;
        $this->config = $config;
    }

    /**
     * @param Schedule $schedule
     *
     * @return void
     */
    public function execute(Schedule $schedule): void
    {
        if (!$this->config->isEnabled()) {
            $schedule->setMessages("BankSync is disabled");
            return;
        }

        if (!$this->config->isAsyncMatching()) {
            $schedule->setMessages("Async matching is disabled");
            return;
        }

        try {
            $schedule->setMessages($this->matcher->matchNewTransactions());
        } catch (Exception $e) {
            $this->logger->error($e);
        }
    }
}
