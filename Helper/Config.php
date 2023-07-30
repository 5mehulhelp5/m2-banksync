<?php

namespace Ibertrand\BankSync\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Config extends AbstractHelper
{
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

    public function getMatchingPattern(string $type): string
    {
        return $this->scopeConfig->getValue("banksync/matching/patterns/{$type}_increment_id") ?? "";
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
        return $this->getWeightconfig('amount')
            + $this->getWeightconfig('purpose')
            + $this->getWeightconfig('payer_name');
    }

    /**
     * @param string $type
     * @return float
     */
    public function getWeightConfig(string $type): float
    {
        return (float)$this->scopeConfig->getValue("banksync/matching/weights/$type");
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
     * @return bool
     */
    public function useStrictAmountMatching(): bool
    {
        return $this->scopeConfig->isSetFlag('banksync/matching/weights/strict_amount');
    }

    /**
     * @return string[]
     */
    public function getPaymentMethods(): array
    {
        return explode(',', $this->scopeConfig->getValue('banksync/matching/filter/payment_methods') ?? "");
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
     * @param int $storeId
     * @return bool
     */
    public function isDunningsEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('banksync/dunnings/enabled', 'store', $storeId);
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isAutoSendDunningsMailEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag('banksync/dunnings/auto_send_mail', 'store', $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return mixed
     */
    public function getDunningEmailTemplate(string $type, int $storeId): mixed
    {
        return $this->scopeConfig->getValue("banksync/dunnings/types/$type/email_template", 'store', $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return bool
     */
    public function isDunningEnabled(string $type, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag("banksync/dunnings/types/$type/enabled", 'store', $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return int
     */
    public function getDunningTypeDelay(string $type, int $storeId): int
    {
        return (int)$this->scopeConfig->getValue("banksync/dunnings/types/$type/delay", 'store', $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return bool
     */
    public function isAttacheTypePdf(string $type, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag("banksync/dunnings/types/$type/attach_pdf", 'store', $storeId);
    }

    /**
     * @param string $type
     * @param int    $storeId
     * @return mixed
     */
    public function getDunningTypeLabel(string $type, int $storeId): mixed
    {
        return $this->scopeConfig->getValue("banksync/dunnings/types/$type/label", 'store', $storeId);
    }

    /**
     * @param int $storeId
     * @return mixed
     */
    public function getDunningSenderIdentity(int $storeId): mixed
    {
        return $this->scopeConfig->getValue("banksync/dunnings/sender_identity", 'store', $storeId);
    }
}
