<?php

namespace Ibertrand\BankSync\Console\Command;

use Exception;
use Ibertrand\BankSync\Helper\Config;
use Ibertrand\BankSync\Helper\Hashes;
use Ibertrand\BankSync\Model\ResourceModel\TempTransaction\CollectionFactory as TempTransactionCollectionFactory;
use Ibertrand\BankSync\Model\ResourceModel\Transaction\CollectionFactory as TransactionCollectionFactory;
use Ibertrand\BankSync\Model\TempTransactionRepository;
use Ibertrand\BankSync\Model\TransactionRepository;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBarFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class TestCommand
 */
class CalcHashes extends Command
{
    protected Hashes $hashes;
    protected Config $config;
    protected ProgressBarFactory $progressBarFactory;
    protected TempTransactionCollectionFactory $tempTransactionCollectionFactory;
    protected TransactionCollectionFactory $transactionCollectionFactory;
    protected TempTransactionRepository $tempTransactionRepository;
    protected TransactionRepository $transactionRepository;

    public function __construct(
        Hashes                           $hashes,
        Config                           $config,
        TempTransactionCollectionFactory $tempTransactionCollectionFactory,
        TransactionCollectionFactory     $transactionCollectionFactory,
        TempTransactionRepository        $tempTransactionRepository,
        TransactionRepository            $transactionRepository,
        ProgressBarFactory               $progressBarFactory,
        string                           $name = null,
    ) {
        parent::__construct($name);
        $this->hashes = $hashes;
        $this->config = $config;
        $this->tempTransactionCollectionFactory = $tempTransactionCollectionFactory;
        $this->transactionCollectionFactory = $transactionCollectionFactory;
        $this->tempTransactionRepository = $tempTransactionRepository;
        $this->transactionRepository = $transactionRepository;
        $this->progressBarFactory = $progressBarFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('banksync:calculate-hashes')
            ->setDescription('Calculate hashes for transactions (if needed after module update)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Recalculate all hashes');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->config->isEnabled()) {
            $output->writeln('BankSync is disabled');
            return Cli::RETURN_FAILURE;
        }
        $progressBar = $this->progressBarFactory->create(['output' => $output]);

        $tempTransactions = $this->tempTransactionCollectionFactory->create();
        $transactions = $this->transactionCollectionFactory->create();
        if (!$input->getOption('all')) {
            $tempTransactions->addFieldToFilter('hash', ['null' => true]);
            $transactions->addFieldToFilter('hash', ['null' => true]);
        }

        $total = $tempTransactions->getSize() + $transactions->getSize();
        $progressBar->setMaxSteps($total);

        $progressBar->start();
        foreach ($tempTransactions as $tempTransaction) {
            $newHash = $this->hashes->calculateHash($tempTransaction);
            if ($newHash !== $tempTransaction->getHash()) {
                $tempTransaction->setHash($newHash);
                try {
                    $this->tempTransactionRepository->save($tempTransaction);
                } catch (Exception $e) {
                    $output->writeln('Error saving temp transaction ' . $tempTransaction->getEntityId());
                    $output->writeln($e->getMessage());
                }
            }
            $progressBar->advance();
        }
        foreach ($transactions as $transaction) {
            $newHash = $this->hashes->calculateHash($transaction);
            if ($newHash !== $transaction->getHash()) {
                $transaction->setHash($newHash);
                try {
                    $this->transactionRepository->save($transaction);
                } catch (Exception $e) {
                    $output->writeln('Error saving transaction ' . $transaction->getEntityId());
                    $output->writeln($e->getMessage());
                }
            }
            $progressBar->advance();
        }
        return Cli::RETURN_SUCCESS;
    }
}
