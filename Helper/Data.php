<?php

namespace Ibertrand\BankSync\Helper;

use Exception;
use Ibertrand\BankSync\Model\TempTransaction;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

class Data extends AbstractHelper
{
    private CustomerResource $customerResource;
    private CustomerFactory $customerFactory;

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
     * @param string $name
     *
     * @return string
     */
    private function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }

    private function getNameComparisonScores($order): array
    {
        $nameScores = [
            $order->getCustomerName() => 1,
            $order->getBillingAddress()->getFirstname() . ' ' . $order->getBillingAddress()->getLastname() => 1,
            $order->getShippingAddress()->getFirstname() . ' ' . $order->getShippingAddress()->getLastname() => 1,
            $order->getBillingAddress()->getCompany() => 1,
            $order->getShippingAddress()->getCompany() => 1,
            $order->getCustomerFirstname() => 0.5,
            $order->getCustomerLastname() => 0.5,
            $order->getBillingAddress()->getFirstname() => 0.5,
            $order->getBillingAddress()->getLastname() => 0.5,
            $order->getShippingAddress()->getFirstname() => 0.5,
            $order->getShippingAddress()->getLastname() => 0.5,
        ];
        arsort($nameScores, SORT_NUMERIC);
        return $nameScores;
    }

    /**
     * @param TempTransaction    $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    private function compareName(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
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

        foreach ($nameScores as $name => $score) {
            $name = $this->normalizeName($name);
            if (empty($name)) {
                continue;
            }
            foreach ($transactionNames as $transactionName) {
                if (str_contains($transactionName, $name)) {
                    return $score;
                }
            }
        }
        return 0;
    }

    private function getIncrementIdPattern(string $type, string $incrementId): string
    {
        $template = $this->scopeConfig->getValue("banksync/matching/patterns/{$type}_increment_id") ?? "";
        return str_replace('{{value}}', $incrementId, $template);
    }

    /**
     * @param string $incrementId
     *
     * @return string
     */
    private function getCustomerIncrementIdPattern(string $incrementId): string
    {
        $template = $this->scopeConfig->getValue('banksync/matching/patterns/customer_increment_id');
        return str_replace('{{value}}', $incrementId, $template);
    }

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
            $results[$documentIncrementId] = 1;
        }

        $orderIncrementId = $document->getOrder()->getIncrementId();
        $pattern = $this->getIncrementIdPattern("order", $orderIncrementId);
        if (preg_match($pattern, $purpose)) {
            $results[$orderIncrementId] = 0.5;
        }

        $nameScores = $this->getNameComparisonScores($document->getOrder());

        foreach ($nameScores as $text => $score) {
            $textNormalized = $this->normalizeName($text);
            if (empty($textNormalized)) {
                continue;
            }
            try {
                $pattern = '/\b' . preg_quote($textNormalized, '/') . '\b/i';
                if (preg_match($pattern, $purpose)) {
                    $results[$text] = $score / 2;
                }
            } catch (Exception $e) {
                $this->_logger->error($e);
            }
        }

        if ($document->getOrder()->getCustomerId()) {
            $customer = $this->loadCustomer($document->getOrder()->getCustomerId());
            /** @noinspection PhpUndefinedMethodInspection */
            $customerIncrementId = $customer->getIncrementId();
            if (!empty($customerIncrementId)) {
                $pattern = $this->getIncrementIdPattern("customer", $customerIncrementId);
                if (preg_match($pattern, $purpose)) {
                    $results[$customerIncrementId] = 0.5;
                }
                $pattern = $this->getIncrementIdPattern("customer", trim($customerIncrementId, '0'));
                if (preg_match($pattern, $purpose)) {
                    $results[trim($customerIncrementId, '0')] = 0.25;
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
    private function comparePurpose(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
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
        return '2023-02-01';
        $config = $this->scopeConfig->getValue('banksync/matching/filter/start_date');
        $timestamp = strtotime($config);
        return $timestamp !== false
            ? date('Y-m-d', strtotime($config))
            : '2000-01-01';
    }

    /**
     * @param TempTransaction $tempTransaction
     * @param Invoice|Creditmemo $document
     *
     * @return float
     */
    public function getMatchConfidence(TempTransaction $tempTransaction, Invoice|Creditmemo $document): float
    {
        $weightAmount = $this->scopeConfig->getValue('banksync/matching/weights/amount');
        $weightPurpose = $this->scopeConfig->getValue('banksync/matching/weights/purpose');
        $weightName = $this->scopeConfig->getValue('banksync/matching/weights/payer_name');
        $amountDif = abs(abs($tempTransaction->getAmount()) - $document->getGrandTotal());

        $amountScore = $weightAmount * ($amountDif < 0.01 ? 1 : (1 - $amountDif / $this->getAmountThreshold()));
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
}
