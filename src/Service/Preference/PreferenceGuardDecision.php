<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MercadoPago\Service\Preference;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PreferenceGuardDecision
{
    public static function reason(array $snapshot, array $payment, array $current): ?string
    {
        if (empty($snapshot['snapshot_hash']) || empty($snapshot['snapshot_json']) || !isset($snapshot['snapshot_total'])) {
            return 'missing_snapshot';
        }
        $currency = (string) ($snapshot['snapshot_currency_iso'] ?? '');
        if (!hash_equals((string) $snapshot['snapshot_hash'], hash('sha256', (string) $snapshot['snapshot_json']))) {
            return 'missing_snapshot';
        }
        try {
            $snapshotTotal = PreferenceSnapshot::money($snapshot['snapshot_total'], $currency);
        } catch (\DomainException) {
            return 'missing_snapshot';
        }
        if ($snapshotTotal !== $snapshot['snapshot_total'] || (float) $snapshotTotal <= 0) {
            return 'missing_snapshot';
        }
        if (($payment['currency_id'] ?? '') !== $currency
            || ($current['snapshot_currency'] ?? null) !== $snapshot['snapshot_currency']
            || ($current['snapshot_shop'] ?? null) !== $snapshot['snapshot_shop']
        ) {
            return 'currency_mismatch';
        }
        $details = $payment['transaction_details'] ?? [];
        if (!is_array($details)) {
            return 'invalid_amount';
        }
        $paymentAmount = $payment['transaction_amount'] ?? null;
        $paidAmount = $details['total_paid_amount'] ?? $paymentAmount;
        $amounts = [$paymentAmount, $paidAmount, $payment['coupon_amount'] ?? 0];
        foreach ($amounts as $amount) {
            if ((!is_int($amount) && !is_float($amount) && !is_string($amount))
                || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0
            ) {
                return 'invalid_amount';
            }
        }
        $principal = min((float) $amounts[0], (float) $amounts[1] + (float) $amounts[2]);
        if (PreferenceSnapshot::money($principal, $currency) !== $snapshotTotal
            || PreferenceSnapshot::money($amounts[0], $currency) !== $snapshotTotal
            || ($current['snapshot_total'] ?? null) !== $snapshotTotal
        ) {
            return 'amount_mismatch';
        }
        if (!hash_equals($snapshot['snapshot_hash'], (string) ($current['snapshot_hash'] ?? ''))) {
            return 'items_mismatch';
        }

        return null;
    }
}
