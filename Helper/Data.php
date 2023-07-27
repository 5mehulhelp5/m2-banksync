<?php

namespace Ibertrand\BankSync\Helper;

use Exception;
use Ibertrand\BankSync\Model\TempTransaction;
use Ibertrand\BankSync\Model\Transaction;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class Data extends AbstractHelper
{
    protected CustomerResource $customerResource;
    protected CustomerFactory $customerFactory;

    public function __construct(
        Context          $context,
        CustomerFactory  $customerFactory,
        CustomerResource $customerResource,
    ) {
        $this->customerFactory = $customerFactory;
        $this->customerResource = $customerResource;

        parent::__construct($context);
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('banksync/general/enabled');
    }

    /**
     * @return bool
     */
    public function isAsyncMatching(): bool
    {
        return (bool)$this->scopeConfig->getValue('banksync/general/async_matching');
    }

    /**
     * @return bool
     */
    public function isSupportCreditmemos(): bool
    {
        return $this->scopeConfig->isSetFlag('banksync/general/support_creditmemos');
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
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    protected function compareName(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        $nameMatches = $this->getNameMatches($tempTransaction, $document);
        return !empty($nameMatches) ? max($nameMatches) : 0;
    }

    protected function getIncrementIdPattern(string $type, string $incrementId): string
    {
        $template = $this->scopeConfig->getValue("banksync/matching/patterns/{$type}_increment_id") ?? "";
        return str_replace('{{value}}', $incrementId, $template);
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
    public function addScore(array $matches, string $key, float $score)
    {
        if ($score > $matches[$key] ?? 0) {
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
     * Returns:
     * 1 if the purpose contains the document IncrementId,
     * 0.5 if the purpose contains the order IncrementId,
     * 0.5 if the purpose contains the customer IncrementId,
     * 0.25 if the purpose contains the customer IncrementId without leading zeros,
     * 0 otherwise.
     *
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    protected function comparePurpose(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        $purposeMatches = $this->getPurposeMatches($tempTransaction, $document);
        if (empty($purposeMatches)) {
            return 0;
        }
        return max($purposeMatches);
    }

    /**
     * @return float
     */
    public function getAmountThreshold(): float
    {
        return (float)$this->scopeConfig->getValue('banksync/matching/filter/amount');
    }

    /**
     * @return float
     */
    public function getAcceptConfidenceThreshold(): float
    {
        return (float)$this->scopeConfig->getValue('banksync/matching/confidence_thresholds/acceptance');
    }

    /**
     * @return float
     */
    public function getAbsoluteConfidenceThreshold(): float
    {
        return $this->scopeConfig->getValue('banksync/matching/weights/amount')
            + $this->scopeConfig->getValue('banksync/matching/weights/purpose')
            + $this->scopeConfig->getValue('banksync/matching/weights/payer_name');
    }

    /**
     * @return float
     */
    public function getMinConfidenceThreshold(): float
    {
        return (float)$this->scopeConfig->getValue('banksync/matching/confidence_thresholds/minimum');
    }

    /**
     * @return float
     */
    public function getDateThreshold(): float
    {
        return (int)$this->scopeConfig->getValue('banksync/matching/filter/date');
    }

    /**
     * @return string
     */
    public function getStartDate(): string
    {
        $config = $this->scopeConfig->getValue('banksync/matching/filter/start_date');
        $timestamp = strtotime($config);
        return $timestamp !== false
            ? date('Y-m-d', strtotime($config))
            : '2000-01-01';
    }

    /**
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    public function getMatchConfidence(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        $weightAmount = $this->scopeConfig->getValue('banksync/matching/weights/amount');
        $weightPurpose = $this->scopeConfig->getValue('banksync/matching/weights/purpose');
        $weightName = $this->scopeConfig->getValue('banksync/matching/weights/payer_name');
        $strictAmount = $this->scopeConfig->isSetFlag('banksync/matching/weights/strict_amount');
        $amountDif = abs(abs($tempTransaction->getAmount()) - $document->getGrandTotal());

        $amountScore = $strictAmount
            ? $amountDif < 0.01 ? $weightAmount : 0
            : $weightAmount * ($amountDif < 0.01 ? 1 : (1 - $amountDif / $this->getAmountThreshold()));
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
    public function loadCustomer(int $customerId): Customer
    {
        $customer = $this->customerFactory->create();
        $this->customerResource->load($customer, $customerId);
        return $customer;
    }

    /**
     * @param TempTransaction|Transaction $transaction
     * @return string
     */
    public function calculateHash(TempTransaction|Transaction $transaction): string
    {
        return sha1(
            implode(
                '|',
                [
                    $transaction->getPayerName(),
                    number_format($transaction->getAmount(), 2, '.', ''),
                    $transaction->getPurpose(),
                    date('Y-m-d H:i:s', strtotime($transaction->getTransactionDate())),
                ]
            )
        );
    }

    /**
     * @param string $type
     * @return string
     */
    public function getNrFilterPattern(string $type): string
    {
        return $this->scopeConfig->getValue("banksync/matching/filter/{$type}_nr_pattern") ?? "";
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
}
