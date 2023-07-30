<?php

namespace Ibertrand\BankSync\Helper;

use Ibertrand\BankSync\Model\TempTransaction;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class Matching extends AbstractHelper
{

    protected CustomerResource $customerResource;
    protected CustomerFactory $customerFactory;
    private Config $config;

    public function __construct(
        Context          $context,
        CustomerFactory  $customerFactory,
        CustomerResource $customerResource,
        Config           $config,
    ) {
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;
        $this->config = $config;

        parent::__construct($context);
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    /**
     * @param array        $nameScores
     * @param array        $halfScoreKeys
     * @param Address|null $address
     * @return void
     */
    protected function addAddressScores(array &$nameScores, array &$halfScoreKeys, ?Order\Address $address): void
    {
        if (!$address) {
            return;
        }
        $nameScores = array_merge($nameScores, [
            $address->getFirstname() . ' ' . $address->getLastname() => 1,
            $address->getCompany() => 1,
        ]);
        $halfScoreKeys = array_merge($halfScoreKeys, [
            $address->getFirstname(),
            $address->getLastname(),
        ]);
    }

    /**
     * @param Order $order
     * @return float[]
     */
    protected function getNameComparisonScores(Order $order): array
    {
        $nameScores = [
            $order->getCustomerName() => 1,
        ];
        $halfScoreKeys = [
            $order->getCustomerFirstname(),
            $order->getCustomerLastname(),
        ];

        $this->addAddressScores($nameScores, $halfScoreKeys, $order->getBillingAddress());
        $this->addAddressScores($nameScores, $halfScoreKeys, $order->getShippingAddress());

        foreach ($halfScoreKeys as $key) {
            // Only set the score to 0.5 if it's not already set (i.e., it's not in the $nameScores array)
            if (!isset($nameScores[$key])) {
                $nameScores[$key] = 0.5;
            }
        }
        arsort($nameScores, SORT_NUMERIC);
        return $nameScores;
    }

    public function getNameMatches(TempTransaction $tempTransaction, Invoice|Creditmemo $document): array
    {
        $transactionName = $tempTransaction->getPayerName();
        $transactionNames = [$transactionName];
        $fixedTransactionName = "";
        if (str_contains($transactionName, ',')) {
            $parts = preg_split('/\s*,\s*/', $transactionName);
            if (count($parts) == 2) {
                $fixedTransactionName = $parts[1] . ' ' . $parts[0];
            }
        }
        if ($fixedTransactionName) {
            $transactionNames[] = $fixedTransactionName;
        }

        array_walk($transactionNames, function (&$name) {
            $name = $this->normalizeName($name);
        });

        $nameScores = $this->getNameComparisonScores($document->getOrder());
        $matches = [];
        foreach ($nameScores as $name => $score) {
            $name = $this->normalizeName($name);
            if (empty($name)) {
                continue;
            }
            foreach ($transactionNames as $transactionName) {
                if (str_contains($transactionName, $name)) {
                    $matches[$name] = $score;
                }
            }
        }
        return $matches;
    }

    /**
     * Aggregate scores using a dynamically weighted sum
     *
     * @param float[] $scores The array of scores, each in [0, 1].
     * @return float The aggregated result, in [0, 1].
     */
    protected function aggregateScores(array $scores): float
    {
        if (empty($scores)) {
            return 0.0;
        }
        rsort($scores);
        $result = 0;
        foreach ($scores as $value) {
            if ($value > 1) {
                $this->_logger->warning("Match value is greater than 1: $value");
                $value = 1;
            }
            if ($value <= 0 || $result >= 1) {
                // exit early if the calculation is done
                break;
            }
            // The result is the sum of the scores, but the score is multiplied by (1 - the current result)
            // This means all matches are aggregated while still returning a value between 0 and 1.
            $result += $value * (1 - $result);
        }
        return $result;
    }

    /**
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    protected function compareName(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        return $this->aggregateScores($this->getNameMatches($tempTransaction, $document));
    }

    protected function getIncrementIdPattern(string $type, string $incrementId): string
    {
        $template = $this->config->getMatchingPattern($type);
        $pattern = str_replace('{{value}}', preg_quote($incrementId), $template);

        if (preg_match($pattern, '') === false) {
            $this->_logger->error("Invalid pattern for $type increment ID: $pattern");
            return "/$ not match possible ^/";
        }
        return $pattern;
    }

    /**
     * Adds a score to the matches array.
     * It makes sure the score is only added if it's greater than the current score for the key.
     *
     * @param array  $matches The array of matches.
     * @param string $key The key of the match to add the score to.
     * @param float  $score The score to be added.
     * @return array The updated array of matches with the added score.
     */
    protected function addScore(array $matches, string $key, float $score): array
    {
        if ($score > ($matches[$key] ?? 0)) {
            $matches[$key] = $score;
        }
        return $matches;
    }

    /**
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     * @return array
     */
    public function getPurposeMatches(TempTransaction $tempTransaction, Invoice|Creditmemo $document): array
    {
        $purpose = trim($tempTransaction->getPurpose() ?? "");
        if (empty($purpose)) {
            return [];
        }

        $results = [];

        $documentIncrementId = $document->getIncrementId();
        $pattern = $this->getIncrementIdPattern("document", $documentIncrementId);
        if (preg_match($pattern, $purpose)) {
            $results = $this->addScore($results, $documentIncrementId, 1);
        }

        $orderIncrementId = $document->getOrder()->getIncrementId();
        $pattern = $this->getIncrementIdPattern("order", $orderIncrementId);
        if (preg_match($pattern, $purpose) && !isset($results[$orderIncrementId])) {
            $results = $this->addScore($results, $orderIncrementId, 0.5);
        }

        $nameScores = $this->getNameComparisonScores($document->getOrder());

        foreach ($nameScores as $text => $score) {
            $textNormalized = $this->normalizeName($text);
            if (empty($textNormalized)) {
                continue;
            }
            $pattern = '/\b' . preg_quote($textNormalized, '/') . '\b/i';
            if (preg_match($pattern, $purpose)) {
                $results = $this->addScore($results, $text, $score / 2);
            }
        }

        if ($document->getOrder()->getCustomerId()) {
            $customer = $this->loadCustomer($document->getOrder()->getCustomerId());
            /** @noinspection PhpUndefinedMethodInspection */
            $customerIncrementId = $customer->getIncrementId();
            if (!empty($customerIncrementId)) {
                $pattern = $this->getIncrementIdPattern("customer", $customerIncrementId);
                if (preg_match($pattern, $purpose)) {
                    $results = $this->addScore($results, $customerIncrementId, 0.5);
                }
                $pattern = $this->getIncrementIdPattern("customer", trim($customerIncrementId, '0'));
                if (preg_match($pattern, $purpose)) {
                    $results = $this->addScore($results, trim($customerIncrementId, '0'), 0.25);
                }
            }
        }

        return $results;
    }

    /**
     * Returns an aggregated score for the purpose:
     * 1 if the purpose contains the document IncrementId,
     * 0.5 if the purpose contains the order IncrementId,
     * 0.5 if the purpose contains the customer IncrementId,
     * 0.25 if the purpose contains the customer IncrementId without leading zeros,
     * 0 otherwise.
     *
     * The weighted aggregation makes sure the resulting score is between 0 and 1.
     *
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    protected function comparePurpose(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        return $this->aggregateScores($this->getPurposeMatches($tempTransaction, $document));
    }

    /**
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    public function getMatchConfidence(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        $weightAmount = $this->config->getWeightConfig('amount');
        $weightPurpose = $this->config->getWeightConfig('purpose');
        $weightName = $this->config->getWeightConfig('payer_name');

        $amountDif = abs(abs($tempTransaction->getAmount()) - $document->getGrandTotal());

        $amountScore = $this->config->useStrictAmountMatching()
            ? $amountDif < 0.01 ? $weightAmount : 0
            : $weightAmount * ($amountDif < 0.01 ? 1 : (1 - $amountDif / $this->config->getAmountThreshold()));
        $purposeScore = $weightPurpose * $this->comparePurpose($tempTransaction, $document);
        $nameScore = $weightName * $this->compareName($tempTransaction, $document);

        return round($amountScore + $purposeScore + $nameScore);
    }

    /**
     * @return string[]
     */
    public function getPaymentMethods(): array
    {
        return explode(',', $this->scopeConfig->getValue('banksync/matching/filter/payment_methods') ?? "");
    }

    /**
     * @param int $customerId
     *
     * @return Customer
     */
    private function loadCustomer(int $customerId): Customer
    {
        $customer = $this->customerFactory->create();
        $this->customerResource->load($customer, $customerId);
        return $customer;
    }
}
