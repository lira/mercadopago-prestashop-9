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
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

/**
 * 2007-2025 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) MercadoPago
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MercadoPago\Service\Notification;

use MercadoPago\Repository\MPTransactionRepository;
use MercadoPago\Service\Notification\Exception\NotificationNotFoundException;
use MercadoPago\Service\Notification\Exception\NotificationProcessingException;
use MercadoPago\Service\Notification\Exception\NotificationRetryableException;
use MercadoPago\Service\Notification\Exception\NotificationSameStateException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;
use MercadoPago\Service\Notification\Exception\PendingCheckoutRetryException;
use MercadoPago\Service\Order\OrderAttemptReference;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaymentNotificationService extends AbstractNotificationService
{
    private const STATUS_MAP = [
        'action_required' => 'MERCADOPAGO_STATUS_0',
        'processing' => 'MERCADOPAGO_STATUS_0',
        'in_review' => 'MERCADOPAGO_STATUS_0',
        'processed' => 'MERCADOPAGO_STATUS_1',
        'authorized' => 'MERCADOPAGO_STATUS_8',
        'pending' => 'MERCADOPAGO_STATUS_7',
        'canceled' => 'MERCADOPAGO_STATUS_2',
        'expired' => 'MERCADOPAGO_STATUS_2',
        'failed' => 'MERCADOPAGO_STATUS_3',
        'refunded' => 'MERCADOPAGO_STATUS_4',
        'charged_back' => 'MERCADOPAGO_STATUS_5',
        'in_mediation' => 'MERCADOPAGO_STATUS_6',
    ];

    /**
     * Classic MP payment statuses (Checkout Pro / merchant_order feed) mapped to
     * the canonical tokens used across this service. The legacy notification flow
     * receives these instead of the PP_ORDER statuses, so they are normalized
     * before the status mapping and downgrade/terminal logic run.
     */
    private const CLASSIC_STATUS_MAP = [
        'approved' => 'processed',
        'authorized' => 'authorized',
        'in_process' => 'processing',
        'pending' => 'pending',
        'in_mediation' => 'in_mediation',
        'rejected' => 'failed',
        'cancelled' => 'canceled',
        'canceled' => 'canceled',
        'refunded' => 'refunded',
        'charged_back' => 'charged_back',
    ];

    private const TERMINAL_STATES = [
        'processed', 'canceled', 'expired', 'failed', 'refunded', 'charged_back',
    ];

    private const DEVOLUTION_STATUSES = ['refunded', 'charged_back', 'in_mediation'];

    private const ALLOWED_TERMINAL_TRANSITIONS = [
        'processed' => ['in_mediation', 'refunded', 'charged_back'],
        'in_mediation' => ['refunded', 'charged_back'],
    ];

    private const NEGATIVE_STATUSES = ['failed', 'canceled', 'expired'];

    private const POSITIVE_STATUSES = ['processed', 'authorized'];

    private const MERCHANT_ORDER_AMOUNT_TOLERANCE = 0.000001;

    public function processPaymentNotificationWithAggregate(
        string $paymentId,
        string $secureKey,
        string $aggregatedStatus,
        float $aggregatedAmount,
        int $cartId = 0,
        array $merchantOrderPaymentIds = [],
        array $merchantOrderPayments = [],
    ): void {
        $transaction = $this->resolveTransaction($paymentId, $cartId);
        if (!$transaction) {
            throw new NotificationNotFoundException('No transaction found for payment ' . $paymentId);
        }
        if ($secureKey !== '' && !$this->isTransactionOwner($transaction, $secureKey)) {
            throw new NotificationProcessingException('secure_key does not match order owner for payment ' . $paymentId);
        }
        $resolvedCartId = (int) ($transaction['cart_id'] ?? 0);
        if ($resolvedCartId <= 0 || ($cartId > 0 && $cartId !== $resolvedCartId)
            || !is_finite($aggregatedAmount) || $aggregatedAmount < 0
        ) {
            throw new NotificationProcessingException('Invalid merchant order transaction');
        }
        $aggregatedStatus = $this->normalizeStatus($aggregatedStatus);
        $useIndividualStatus = !$this->isSupportedStatus($aggregatedStatus) || $aggregatedAmount <= 0;
        $confirmedPayments = [];
        foreach ($merchantOrderPayments as $snapshot) {
            if (!is_array($snapshot)) {
                throw new NotificationProcessingException('Invalid merchant order payment');
            }
            $id = $this->merchantOrderPaymentId($snapshot['id'] ?? null);
            if (isset($confirmedPayments[$id])) {
                throw new NotificationProcessingException('Duplicate merchant order payment');
            }
            $confirmedPayments[$id] = $this->confirmMerchantOrderPayment($id, $resolvedCartId, $snapshot);
        }
        if ($merchantOrderPayments !== [] && !isset($confirmedPayments[$paymentId])) {
            throw new NotificationProcessingException('Selected payment is absent from merchant order');
        }
        if ($confirmedPayments === []) {
            $snapshot = $useIndividualStatus ? null : [
                'status' => $aggregatedStatus,
                'transaction_amount' => $aggregatedAmount,
            ];
            $confirmedPayments[$paymentId] = $this->confirmMerchantOrderPayment($paymentId, $resolvedCartId, $snapshot);
        } else {
            $merchantOrderPayments = array_values($confirmedPayments);
            $merchantOrderPaymentIds = array_column($merchantOrderPayments, 'id');
            $confirmedAmount = $this->resolveAggregatedAmountForStatus($merchantOrderPayments, $aggregatedStatus);
            if (!$useIndividualStatus
                && abs($confirmedAmount - $aggregatedAmount) > self::MERCHANT_ORDER_AMOUNT_TOLERANCE
            ) {
                throw new NotificationRetryableException('Merchant order aggregate amount is stale');
            }
            $orderTotal = $this->confirmedMerchantOrderTotal($transaction);
            if ($orderTotal > 0) {
                $confirmedStatus = $this->resolveAggregatedStatus($merchantOrderPayments, $orderTotal);
                if ($aggregatedStatus === 'processed' && $confirmedStatus !== 'processed') {
                    throw new NotificationRetryableException('Merchant order approval is not fully funded');
                }
                $aggregatedStatus = $confirmedStatus;
                $aggregatedAmount = $this->resolveAggregatedAmountForStatus($merchantOrderPayments, $aggregatedStatus);
            }
        }
        if ($useIndividualStatus) {
            if (!$this->isSupportedStatus($aggregatedStatus)) {
                $aggregatedStatus = $this->normalizeStatus($confirmedPayments[$paymentId]['status']);
            }
            $aggregatedAmount = $this->resolveAggregatedAmountForStatus(array_values($confirmedPayments), $aggregatedStatus);
        }
        if (!is_finite($aggregatedAmount)) {
            throw new NotificationProcessingException('Invalid confirmed merchant order amount');
        }
        if ($aggregatedStatus === 'processed' && $aggregatedAmount <= 0) {
            throw new NotificationRetryableException('Payment approval has no confirmed paid amount');
        }
        $this->applyOrderStateWithStatusLock(
            $transaction,
            $aggregatedStatus,
            $aggregatedAmount,
            $paymentId,
            [],
            $merchantOrderPaymentIds,
            $merchantOrderPayments
        );
    }

    private function confirmedMerchantOrderTotal(array $transaction): float
    {
        $psOrderId = $this->resolvePSOrderId($transaction);
        if ($psOrderId > 0) {
            $order = new \Order($psOrderId);
            if ($order->id) {
                $total = $this->getMaterializedOrderTotal($order, (int) $transaction['cart_id']);
                if ($total > 0) {
                    return $total;
                }
            }
        }

        return $this->merchantOrderAmount($transaction['total'] ?? 0);
    }

    private function merchantOrderPaymentId(mixed $id): string
    {
        $id = is_int($id) ? (string) $id : $id;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1) {
            throw new NotificationProcessingException('Invalid merchant order payment id');
        }

        return $id;
    }

    private function merchantOrderAmount(mixed $amount): float
    {
        if ((!is_int($amount) && !is_float($amount) && !is_string($amount))
            || !is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0
        ) {
            throw new NotificationProcessingException('Invalid merchant order payment amount');
        }

        return (float) $amount;
    }

    /**
     * Confirm each entry before acquiring the cart lock. Compare nominal amounts
     * with nominal amounts: installment interest is not part of transaction_amount.
     * Paid amounts and coupons are compared separately when the snapshot supplies them.
     */
    private function confirmMerchantOrderPayment(string $paymentId, int $cartId, ?array $snapshot): array
    {
        $paymentId = $this->merchantOrderPaymentId($paymentId);
        try {
            $result = $this->mpApi->getPayment($paymentId);
        } catch (\Throwable $cause) {
            throw new NotificationRetryableException('Payment API request failed', 0, $cause);
        }
        $httpStatus = (int) ($result['status'] ?? 0);
        if ($httpStatus === 0 || $httpStatus === 429 || $httpStatus >= 500) {
            throw new NotificationRetryableException('Payment API is temporarily unavailable');
        }
        $payment = $result['response'] ?? null;
        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($payment)) {
            throw new NotificationProcessingException('Could not confirm merchant order payment');
        }

        $canonicalId = $this->merchantOrderPaymentId($payment['id'] ?? null);
        $reference = $payment['external_reference'] ?? null;
        $reference = is_int($reference) ? (string) $reference : $reference;
        $status = $this->normalizeStatus(is_string($payment['status'] ?? null) ? $payment['status'] : '');
        if ($canonicalId !== $paymentId || !is_string($reference)
            || preg_match('/^[1-9][0-9]{0,9}$/D', $reference) !== 1
            || (int) $reference !== $cartId || !$this->isSupportedStatus($status)
        ) {
            throw new NotificationProcessingException('Payment does not match merchant order transaction');
        }
        $amount = $this->merchantOrderAmount($payment['transaction_amount'] ?? null);
        $coupon = $this->merchantOrderAmount($payment['coupon_amount'] ?? 0);
        if (in_array($status, self::POSITIVE_STATUSES, true) && $amount + $coupon <= 0) {
            throw new NotificationRetryableException('Payment approval has no confirmed amount');
        }
        $canonicalDetails = $payment['transaction_details'] ?? null;
        if ($canonicalDetails !== null && !is_array($canonicalDetails)) {
            throw new NotificationProcessingException('Invalid Payment API transaction details');
        }
        if ($status === 'processed' && is_array($canonicalDetails)
            && array_key_exists('total_paid_amount', $canonicalDetails)
        ) {
            $canonicalPaid = $this->merchantOrderAmount($canonicalDetails['total_paid_amount']);
            if ($status === 'processed'
                && ($canonicalPaid + $coupon <= 0
                    || $canonicalPaid + $coupon < $amount - self::MERCHANT_ORDER_AMOUNT_TOLERANCE)
            ) {
                throw new NotificationRetryableException('Payment approval has no sufficient confirmed paid amount');
            }
        }

        $confirmed = ['id' => $canonicalId, 'status' => $status, 'transaction_amount' => $amount];
        if ($snapshot === null) {
            $confirmed['coupon_amount'] = $coupon;

            return $confirmed;
        }
        $snapshotStatus = $this->normalizeStatus(
            is_string($snapshot['status'] ?? null) ? $snapshot['status'] : ''
        );
        if (!$this->isSupportedStatus($snapshotStatus)) {
            throw new NotificationProcessingException('Invalid merchant order payment status');
        }
        $snapshotAmount = $this->merchantOrderAmount($snapshot['transaction_amount'] ?? null);
        if ($snapshotStatus !== $status
            || abs($snapshotAmount - $amount) > self::MERCHANT_ORDER_AMOUNT_TOLERANCE
        ) {
            throw new NotificationRetryableException('Merchant order payment snapshot is stale');
        }
        if (array_key_exists('coupon_amount', $snapshot)) {
            $snapshotCoupon = $this->merchantOrderAmount($snapshot['coupon_amount']);
            if (abs($snapshotCoupon - $coupon) > self::MERCHANT_ORDER_AMOUNT_TOLERANCE) {
                throw new NotificationRetryableException('Merchant order coupon snapshot is stale');
            }
            $confirmed['coupon_amount'] = $coupon;
        }
        if (array_key_exists('transaction_details', $snapshot)
            && !is_array($snapshot['transaction_details'])
        ) {
            throw new NotificationProcessingException('Invalid merchant order transaction details');
        }
        $snapshotPaid = $snapshot['transaction_details']['total_paid_amount']
            ?? $snapshot['total_paid_amount'] ?? null;
        if ($snapshotPaid !== null) {
            $paid = $this->merchantOrderAmount($snapshotPaid);
            $details = $payment['transaction_details'] ?? null;
            $canonicalPaid = $this->merchantOrderAmount(
                is_array($details) ? ($details['total_paid_amount'] ?? null) : null
            );
            if (abs($paid - $canonicalPaid) > self::MERCHANT_ORDER_AMOUNT_TOLERANCE) {
                throw new NotificationRetryableException('Merchant order paid amount snapshot is stale');
            }
            // Preserve the existing aggregate amount semantics of nested vs classic snapshots.
            if (isset($snapshot['transaction_details']['total_paid_amount'])) {
                $confirmed['transaction_details'] = ['total_paid_amount' => $canonicalPaid];
            }
        }

        return $confirmed;
    }

    public function processPaymentNotification(
        string $paymentId,
        string $secureKey = '',
        int $cartId = 0,
        array $merchantOrderPaymentIds = [],
        array $merchantOrderPayments = [],
    ): void {
        $transaction = $this->resolveTransaction($paymentId, $cartId);

        if (!$transaction) {
            \PrestaShopLogger::addLog(
                '[MP Notification] No transaction found for payment ' . $paymentId,
                2,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationNotFoundException('No transaction found for payment ' . $paymentId);
        }

        if ($secureKey !== '' && !$this->isTransactionOwner($transaction, $secureKey)) {
            \PrestaShopLogger::addLog(
                '[MP Notification] secure_key does not match order owner for payment ' . $paymentId,
                2,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationProcessingException('secure_key does not match order owner for payment ' . $paymentId);
        }

        $apiResult = $this->mpApi->getPayment($paymentId);
        $httpStatus = (int) ($apiResult['status'] ?? 0);
        $payment = $apiResult['response'] ?? null;

        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($payment)) {
            throw new NotificationProcessingException('Could not fetch payment ' . $paymentId . ' — HTTP ' . $httpStatus);
        }

        $paymentStatus = $payment['status'] ?? null;
        $transactionDetails = $payment['transaction_details'] ?? [];
        if (!is_string($paymentStatus) || !is_array($transactionDetails)) {
            throw new NotificationProcessingException('Payment API returned an invalid response');
        }

        $totalPaid = $transactionDetails['total_paid_amount'] ?? 0;
        if ((!is_int($totalPaid) && !is_float($totalPaid) && !is_string($totalPaid))
            || !is_numeric($totalPaid)
            || !is_finite((float) $totalPaid)
            || (float) $totalPaid < 0
        ) {
            throw new NotificationProcessingException('Payment API returned an invalid response');
        }

        $mpStatus = $this->normalizeStatus($paymentStatus);
        if (!$this->isSupportedStatus($mpStatus)) {
            throw new NotificationProcessingException('Payment API returned an unsupported status');
        }

        $this->applyOrderStateWithStatusLock(
            $transaction,
            $mpStatus,
            (float) $totalPaid,
            $paymentId,
            [],
            $merchantOrderPaymentIds,
            $merchantOrderPayments
        );
    }

    public function processOrderNotification(array $notification, string $secureKey): void
    {
        [$notificationStatus, $cartId, $paymentId, $paymentsDetails] = $this->validateOrderNotification($notification);
        $transaction = $this->resolveTransaction($paymentId, $cartId);

        if (!$transaction) {
            \PrestaShopLogger::addLog(
                '[MP Notification] No transaction found for cart ' . $cartId,
                2,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationNotFoundException('No transaction found for cart ' . $cartId);
        }

        if ((int) ($transaction['cart_id'] ?? 0) !== $cartId) {
            throw new NotificationProcessingException('Payment does not match the resolved transaction');
        }

        if ($secureKey === '' || !$this->isTransactionOwner($transaction, $secureKey)) {
            throw new NotificationSkippedException('pp_order customer key does not match transaction owner', 'ownership_mismatch');
        }

        if ($paymentId === '') {
            if ($notificationStatus !== 'processing') {
                throw new NotificationProcessingException('pp_order payment reference is required for this status');
            }

            throw new NotificationSkippedException('pp_order payment is not available yet', 'awaiting_payment_id');
        }

        [$status, $totalPaid] = $this->reconcileOrderPayment($paymentId, $cartId);

        $storedPaymentId = (string) ($transaction['payment_id'] ?? '');
        if ($storedPaymentId !== ''
            && $storedPaymentId !== $paymentId
            && !$this->isInternalPPOrderPaymentId($storedPaymentId)
        ) {
            throw new NotificationProcessingException('Payment does not match the resolved transaction');
        }

        if ($notificationStatus !== $status) {
            \PrestaShopLogger::addLog(
                '[MP Notification] pp_order status reconciled with Payment API',
                1,
                null,
                'MPNotification',
                0,
                true
            );
        }

        $this->applyOrderStateWithStatusLock(
            $transaction,
            $status,
            $totalPaid,
            $paymentId,
            $paymentsDetails
        );
    }

    /**
     * @return array{0: string, 1: int, 2: string, 3: array}
     */
    private function validateOrderNotification(array $notification): array
    {
        $status = $notification['status'] ?? null;
        $externalReference = $notification['external_reference'] ?? null;
        $paymentsDetails = $notification['payments_details'] ?? null;
        $totalPaid = $notification['total_paid'] ?? 0;

        if (($notification['transaction_type'] ?? null) !== 'pp_order'
            || !is_string($status)
            || !$this->isSupportedStatus($status)
        ) {
            throw new NotificationProcessingException('Invalid pp_order notification schema');
        }

        $externalReference = is_int($externalReference) ? (string) $externalReference : $externalReference;
        if (!is_string($externalReference)
            || preg_match('/^[1-9][0-9]{0,9}$/D', $externalReference) !== 1
        ) {
            throw new NotificationProcessingException('Invalid pp_order notification schema');
        }

        if (!is_array($paymentsDetails) || count($paymentsDetails) === 0 || count($paymentsDetails) > 20) {
            throw new NotificationProcessingException('Invalid pp_order notification schema');
        }

        $firstPayment = $paymentsDetails[0] ?? null;
        $references = is_array($firstPayment) ? ($firstPayment['references'] ?? null) : null;
        $paymentId = is_array($references) ? ($references['payment_id'] ?? '') : '';
        $paymentId = is_int($paymentId) ? (string) $paymentId : $paymentId;
        if (!is_string($paymentId)
            || ($paymentId !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $paymentId) !== 1)
        ) {
            throw new NotificationProcessingException('Invalid pp_order notification schema');
        }

        if ((!is_int($totalPaid) && !is_float($totalPaid) && !is_string($totalPaid))
            || !is_numeric($totalPaid)
            || !is_finite((float) $totalPaid)
            || (float) $totalPaid < 0
        ) {
            throw new NotificationProcessingException('Invalid pp_order notification schema');
        }

        return [$status, (int) $externalReference, $paymentId, $paymentsDetails];
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function reconcileOrderPayment(string $paymentId, int $cartId): array
    {
        try {
            $apiResult = $this->mpApi->getPayment($paymentId);
        } catch (\Throwable $th) {
            throw new NotificationRetryableException('Payment API request failed');
        }

        $httpStatus = (int) ($apiResult['status'] ?? 0);
        $payment = $apiResult['response'] ?? null;

        if ($httpStatus === 0 || $httpStatus === 429 || $httpStatus >= 500) {
            throw new NotificationRetryableException('Payment API is temporarily unavailable');
        }

        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($payment)) {
            throw new NotificationProcessingException('Could not reconcile pp_order payment');
        }

        $canonicalPaymentId = $payment['id'] ?? null;
        $canonicalPaymentId = is_int($canonicalPaymentId) ? (string) $canonicalPaymentId : $canonicalPaymentId;
        $externalReference = $payment['external_reference'] ?? null;
        $externalReference = is_int($externalReference) ? (string) $externalReference : $externalReference;
        $status = $this->normalizeStatus(is_string($payment['status'] ?? null) ? $payment['status'] : '');
        $transactionDetails = $payment['transaction_details'] ?? null;
        $totalPaid = is_array($transactionDetails) ? ($transactionDetails['total_paid_amount'] ?? 0) : null;

        if (!is_string($canonicalPaymentId)
            || $canonicalPaymentId !== $paymentId
            || !is_string($externalReference)
            || preg_match('/^[1-9][0-9]{0,9}$/D', $externalReference) !== 1
            || (int) $externalReference !== $cartId
            || !$this->isSupportedStatus($status)
            || !is_array($transactionDetails)
            || (!is_int($totalPaid) && !is_float($totalPaid) && !is_string($totalPaid))
            || !is_numeric($totalPaid)
            || !is_finite((float) $totalPaid)
            || (float) $totalPaid < 0
        ) {
            throw new NotificationProcessingException('Payment API response does not match pp_order notification');
        }

        return [$status, (float) $totalPaid];
    }

    private function updateOrderPaymentTransactionId(array $transaction, array $paymentsDetails): void
    {
        $paymentId = (string) ($paymentsDetails[0]['references']['payment_id'] ?? '');
        $psOrderId = $this->resolvePSOrderId($transaction);

        if ($paymentId === '' || $psOrderId <= 0) {
            return;
        }

        $order = new \Order($psOrderId);
        $payments = $order->id ? $order->getOrderPaymentCollection() : [];

        $firstPayment = $payments[0] ?? null;
        if ($firstPayment instanceof \OrderPayment && (string) $firstPayment->transaction_id !== $paymentId) {
            $firstPayment->transaction_id = $paymentId;
            if ($firstPayment->update() !== true) {
                throw new NotificationRetryableException('Could not persist PrestaShop payment id');
            }
        }
    }

    /**
     * Serialize the transaction status and PrestaShop order mutation. Waiting
     * callbacks refresh the row after acquiring the lock so stale webhook data
     * cannot overwrite a newer order state.
     */
    private function applyOrderStateWithStatusLock(
        array $transaction,
        string $status,
        float $totalPaid = 0.0,
        string $paymentId = '',
        array $paymentsDetails = [],
        array $merchantOrderPaymentIds = [],
        array $merchantOrderPayments = [],
    ): void {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);
        $cartId = (int) ($transaction['cart_id'] ?? 0);

        if ($transactionId <= 0 || $cartId <= 0) {
            throw new NotificationProcessingException('Resolved transaction is invalid');
        }

        $lockedTransaction = $this->executeRetryablePersistenceOperation(
            fn (): ?array => $this->repository->lockAndRefreshTransaction($transaction),
            'Could not acquire transaction processing lock'
        );
        if ($lockedTransaction === null) {
            throw new NotificationRetryableException('Could not acquire transaction processing lock');
        }

        try {
            if ((int) ($lockedTransaction['id_mp_transaction'] ?? 0) !== $transactionId
                || (int) ($lockedTransaction['cart_id'] ?? 0) !== $cartId
            ) {
                throw new NotificationProcessingException('Locked transaction does not match notification');
            }

            $currentStatus = (string) ($lockedTransaction['payment_status'] ?? '');
            $sameMerchantOrderRecovery = $this->isSameMerchantOrderRecovery(
                $lockedTransaction,
                $currentStatus,
                $status,
                $paymentId,
                $merchantOrderPaymentIds
            );
            if ($this->isDowngrade($currentStatus, $status) && !$sameMerchantOrderRecovery) {
                throw new NotificationSkippedException('Downgrade blocked: ' . $currentStatus . ' → ' . $status, 'downgrade');
            }

            $storedPaymentId = (string) ($lockedTransaction['payment_id'] ?? '');
            $atomicPaymentRotation = $status !== $currentStatus
                && $this->isSameMerchantOrderPaymentRotation(
                    $storedPaymentId,
                    $paymentId,
                    $merchantOrderPaymentIds
                );
            if (!$atomicPaymentRotation) {
                $this->persistPaymentId($lockedTransaction, $paymentId, $merchantOrderPaymentIds);
            }
            if ($paymentId !== '') {
                $lockedTransaction['payment_id'] = $paymentId;
            }

            $reserved = false;
            if ($status !== $currentStatus) {
                $reserved = $this->executeRetryablePersistenceOperation(
                    $atomicPaymentRotation
                        ? fn (): ?bool => $this->repository->compareAndSetPaymentRotation(
                            $transactionId,
                            $paymentId,
                            $status,
                            $storedPaymentId,
                            $currentStatus
                        )
                        : fn (): ?bool => $this->repository->updatePaymentStatus(
                            $transactionId,
                            $status,
                            $currentStatus
                        ),
                    'Could not persist transaction status'
                );
                if ($reserved === null) {
                    throw new NotificationRetryableException('Could not persist transaction status');
                }
                if ($reserved === false) {
                    throw new NotificationSkippedException('Concurrent notification already changed the transaction', 'concurrent_update');
                }
            } else {
                $received = $this->executeRetryablePersistenceOperation(
                    fn (): bool => $this->repository->markWebhookReceived($transactionId),
                    'Could not mark webhook as received'
                );
                if (!$received) {
                    throw new NotificationRetryableException('Could not mark webhook as received');
                }
            }

            $sameStateException = null;
            try {
                $this->applyOrderState($lockedTransaction, $status, $totalPaid);
            } catch (NotificationSameStateException $e) {
                $sameStateException = $e;
            } catch (\Throwable $th) {
                $preserveTerminalDisposition = $th instanceof NotificationSkippedException
                    && in_array(
                        $th->getReason(),
                        [
                            'negative_without_order',
                            'invalid_cart',
                            'uncertain_order',
                        ],
                        true
                    );
                $preserveApprovedPendingRecovery = $sameMerchantOrderRecovery
                    && $th instanceof PendingCheckoutRetryException;

                if ($reserved
                    && !$preserveTerminalDisposition
                    && !$preserveApprovedPendingRecovery
                ) {
                    $rolledBack = $this->executeRetryablePersistenceOperation(
                        $atomicPaymentRotation
                            ? fn (): ?bool => $this->repository->compareAndSetPaymentRotation(
                                $transactionId,
                                $storedPaymentId,
                                $currentStatus,
                                $paymentId,
                                $status
                            )
                            : fn (): ?bool => $this->repository->updatePaymentStatus(
                                $transactionId,
                                $currentStatus,
                                $status
                            ),
                        'Could not roll back reserved transaction status'
                    );
                    if ($rolledBack !== true) {
                        \PrestaShopLogger::addLog(
                            '[MP Notification] Could not roll back reserved transaction status',
                            3,
                            null,
                            'MPNotification',
                            0,
                            true
                        );

                        throw new NotificationRetryableException('Could not roll back reserved transaction status', 0, $th);
                    }
                }

                if ($th instanceof NotificationNotFoundException
                    || $th instanceof NotificationProcessingException
                    || $th instanceof NotificationRetryableException
                    || $th instanceof NotificationSkippedException
                ) {
                    throw $th;
                }

                throw new NotificationRetryableException('Could not apply PrestaShop order state', 0, $th);
            }

            if ($paymentsDetails !== []) {
                $this->updateOrderPaymentTransactionId($lockedTransaction, $paymentsDetails);
            }

            if ($merchantOrderPayments !== []) {
                try {
                    $this->syncConfirmedMerchantOrderPayments(
                        $this->resolvePSOrderId($lockedTransaction),
                        $merchantOrderPayments
                    );
                } catch (NotificationRetryableException $exception) {
                    throw $exception;
                } catch (\Throwable $th) {
                    throw new NotificationRetryableException('Could not persist PrestaShop payment synchronization', 0, $th);
                }
            }

            if ($sameStateException !== null) {
                throw $sameStateException;
            }
        } finally {
            $this->executeRetryablePersistenceOperation(
                fn () => $this->repository->releaseNotificationLock($cartId),
                'Could not release transaction processing lock'
            );
        }
    }

    private function applyOrderState(array $transaction, string $status, float $totalPaid = 0.0): void
    {
        if (MPTransactionRepository::hasInvalidOrderCreationMarker($transaction)) {
            throw new NotificationSkippedException('Checkout cart changed after the remote payment attempt', 'invalid_cart');
        }

        if (MPTransactionRepository::hasUncertainOrderCreationMarker($transaction)) {
            throw new NotificationSkippedException('Checkout order creation requires manual reconciliation', 'uncertain_order');
        }

        $psOrderId = $this->resolvePSOrderId($transaction);
        if ($psOrderId <= 0
            && MPTransactionRepository::hasPendingOrderCreationMarker($transaction)
            && !in_array($status, self::NEGATIVE_STATUSES, true)
        ) {
            throw new PendingCheckoutRetryException('Checkout must retry the reserved remote payment attempt');
        }
        $storedOrderId = (int) ($transaction['merchant_order_id'] ?? 0);

        if ($psOrderId > 0
            && $storedOrderId <= 0
            && !$this->hasExactTransactionOrderLink($transaction, $psOrderId)
        ) {
            $candidateOrder = new \Order($psOrderId);
            if (!$candidateOrder->id
                || !$this->transactionOwnsOrder($transaction, $psOrderId, $candidateOrder)
            ) {
                throw new NotificationSkippedException('Transaction does not own the cart order', 'ownership_mismatch');
            }
        }

        if ($psOrderId <= 0 && in_array($status, self::NEGATIVE_STATUSES, true)) {
            throw new NotificationSkippedException('Negative payment status has no PrestaShop order to update', 'negative_without_order');
        }

        $newStateId = $this->mapStatusToPSState($status);

        if ($newStateId <= 0) {
            throw new NotificationProcessingException('No PrestaShop state is configured for payment status');
        }

        if ($psOrderId <= 0) {
            $psOrderId = $this->createOrderFromNotification($transaction, $status, $totalPaid);
            if ($psOrderId <= 0) {
                \PrestaShopLogger::addLog(
                    '[MP Notification] Could not create order for cart ' . (int) $transaction['cart_id'],
                    3,
                    null,
                    'MPNotification',
                    0,
                    true
                );
                throw new NotificationNotFoundException('Could not create order for cart ' . (int) $transaction['cart_id']);
            }
        }

        $order = new \Order($psOrderId);
        if (!$order->id) {
            throw new NotificationProcessingException('Order ' . $psOrderId . ' not found in PS');
        }

        $currentStateId = (int) $order->current_state;
        if ($currentStateId !== $newStateId
            && !in_array($status, self::DEVOLUTION_STATUSES, true)
            && !$this->isCurrentStateAllowed($transaction, $currentStateId, $psOrderId, $order)
        ) {
            $reason = $currentStateId === (int) \Configuration::get('PS_OS_PREPARATION')
                ? 'ownership_mismatch'
                : 'state_not_allowed';
            throw new NotificationSkippedException('Order ' . $psOrderId . ' state is not eligible for automatic update', $reason);
        }

        if ($status === 'processed' && $totalPaid > 0) {
            $orderTotal = $this->getMaterializedOrderTotal(
                $order,
                (int) $transaction['cart_id']
            );
            if ($orderTotal > 0 && $totalPaid < ($orderTotal - 0.01)) {
                \PrestaShopLogger::addLog(
                    '[MP Notification] Possible fraud on order ' . $psOrderId
                    . ': paid ' . $totalPaid . ' but order total is ' . $orderTotal,
                    3,
                    null,
                    'Order',
                    $psOrderId,
                    true
                );
                $fraudState = (int) \Configuration::get('MERCADOPAGO_STATUS_9');
                $newStateId = $fraudState > 0 ? $fraudState : (int) \Configuration::get('PS_OS_ERROR');
            }
        }

        $orders = \Order::getByReference($order->reference);
        if (count($orders) === 0) {
            $orders = [$order];
        }

        $allOrdersAlreadyUpdated = true;
        foreach ($orders as $splitOrder) {
            /** @var \Order $splitOrder */
            if ((int) $splitOrder->current_state !== $newStateId) {
                $allOrdersAlreadyUpdated = false;
                break;
            }
        }

        if ($allOrdersAlreadyUpdated) {
            throw new NotificationSameStateException('All orders already in state ' . $newStateId);
        }

        foreach ($orders as $splitOrder) {
            /** @var \Order $splitOrder */
            if ((int) $splitOrder->current_state !== $newStateId) {
                $splitOrder->setCurrentState($newStateId);
                \PrestaShopLogger::addLog(
                    '[MP Notification] Order ' . $splitOrder->id
                    . ' state updated to ' . $newStateId
                    . ' (status: ' . $status . ')',
                    1,
                    null,
                    'Order',
                    (int) $splitOrder->id,
                    true
                );
            }
        }
    }

    /**
     * Read the immutable amount materialized by validateOrder(). The live cart
     * is intentionally not used: the checkout neutralizes its temporary
     * Mercado Pago rule after order creation, so recalculating the cart would
     * turn a legitimate discounted payment into a false underpayment.
     */
    private function getMaterializedOrderTotal(\Order $order, int $cartId): float
    {
        $reference = trim((string) $order->reference);
        if ($reference === '') {
            return max(0.0, (float) $order->total_paid_tax_incl);
        }

        $rows = $this->executeRetryablePersistenceOperation(
            fn (): array => $this->repository->findOrderTotalsByReferenceAndCart(
                $reference,
                $cartId
            ),
            'Could not read materialized PrestaShop order totals'
        );
        if ($rows === []) {
            throw new NotificationRetryableException('Could not read materialized PrestaShop order totals');
        }

        $orderTotal = 0.0;
        foreach ($rows as $row) {
            $rowOrderId = (int) ($row['id_order'] ?? 0);
            $rowTotal = $row['total_paid_tax_incl'] ?? null;
            if ($rowOrderId <= 0
                || (!is_int($rowTotal) && !is_float($rowTotal) && !is_string($rowTotal))
                || !is_numeric($rowTotal)
                || !is_finite((float) $rowTotal)
                || (float) $rowTotal < 0
            ) {
                throw new NotificationRetryableException('Materialized PrestaShop order totals are invalid');
            }

            $orderTotal += (float) $rowTotal;
        }

        if (!is_finite($orderTotal)) {
            throw new NotificationRetryableException('Materialized PrestaShop order total is invalid');
        }

        return $orderTotal;
    }

    /**
     * Locate the transaction row from the MP payment id, falling back to the
     * cart id (external_reference) when the payment id is not yet stored — which
     * is the case for Checkout Pro, whose preference transaction only knows the
     * cart until a payment exists.
     *
     * @return array|null
     */
    private function resolveTransaction(string $paymentId, int $cartId): ?array
    {
        $transaction = $paymentId !== '' ? $this->repository->findByPaymentId($paymentId) : null;

        if (!$transaction && $cartId > 0) {
            $transaction = $this->repository->findByCartId($cartId);
        }

        return $transaction;
    }

    /**
     * Persist the MP payment id on the transaction so later payment-topic IPNs
     * can be reconciled directly by payment id.
     */
    private function persistPaymentId(
        array $transaction,
        string $paymentId,
        array $merchantOrderPaymentIds = [],
    ): void {
        $storedPaymentId = (string) ($transaction['payment_id'] ?? '');
        if ($paymentId === '' || $storedPaymentId === $paymentId) {
            return;
        }

        $sameMerchantOrder = $this->isSameMerchantOrderPaymentRotation(
            $storedPaymentId,
            $paymentId,
            $merchantOrderPaymentIds
        );
        if ($storedPaymentId !== ''
            && !$this->isInternalPPOrderPaymentId($storedPaymentId)
            && !$sameMerchantOrder
        ) {
            throw new NotificationProcessingException('Locked transaction belongs to another payment');
        }

        $updated = $this->executeRetryablePersistenceOperation(
            fn (): bool => $this->repository->update(
                ['payment_id' => $paymentId],
                (int) $transaction['id_mp_transaction']
            ),
            'Could not persist Mercado Pago payment id'
        );
        if (!$updated) {
            throw new NotificationRetryableException('Could not persist Mercado Pago payment id');
        }
    }

    private function isSameMerchantOrderRecovery(
        array $transaction,
        string $currentStatus,
        string $newStatus,
        string $paymentId,
        array $merchantOrderPaymentIds,
    ): bool {
        if (!in_array($currentStatus, self::NEGATIVE_STATUSES, true)
            || !in_array($newStatus, self::POSITIVE_STATUSES, true)
        ) {
            return false;
        }

        return $this->isSameMerchantOrderPaymentRotation(
            (string) ($transaction['payment_id'] ?? ''),
            $paymentId,
            $merchantOrderPaymentIds
        );
    }

    private function isSameMerchantOrderPaymentRotation(
        string $storedPaymentId,
        string $paymentId,
        array $merchantOrderPaymentIds,
    ): bool {
        return $storedPaymentId !== ''
            && $paymentId !== ''
            && $storedPaymentId !== $paymentId
            && in_array($storedPaymentId, $merchantOrderPaymentIds, true)
            && in_array($paymentId, $merchantOrderPaymentIds, true);
    }

    /**
     * Validate the notification's customer key. When the order already exists it
     * is checked against the order's customer; otherwise (the order may be
     * created by this very notification) it is checked against the cart's
     * customer — keeping the same ownership guarantee in both cases.
     */
    private function isTransactionOwner(array $transaction, string $secureKey): bool
    {
        $psOrderId = $this->resolvePSOrderId($transaction);
        if ($psOrderId > 0) {
            $order = new \Order($psOrderId);
            if ($order->id) {
                $customer = new \Customer((int) $order->id_customer);
                if ($customer->id > 0 && hash_equals($customer->secure_key, $secureKey)) {
                    return true;
                }
            }
        }

        $cartId = (int) ($transaction['cart_id'] ?? 0);
        if ($cartId > 0) {
            $cart = new \Cart($cartId);
            if ($cart->id) {
                $customer = new \Customer((int) $cart->id_customer);

                return $customer->id > 0 && hash_equals($customer->secure_key, $secureKey);
            }
        }

        return false;
    }

    private function isInternalPPOrderPaymentId(string $paymentId): bool
    {
        return strpos($paymentId, 'PPPAY') === 0;
    }

    private function resolvePSOrderId(array $transaction): int
    {
        $merchantOrderId = (int) ($transaction['merchant_order_id'] ?? 0);
        if ($merchantOrderId > 0) {
            return $merchantOrderId;
        }

        return (int) \Order::getIdByCartId((int) $transaction['cart_id']);
    }

    /**
     * Normalize a classic MP payment status (Checkout Pro / merchant_order feed)
     * to the canonical token used across this service. PP_ORDER statuses pass
     * through unchanged.
     */
    private function normalizeStatus(string $status): string
    {
        return self::CLASSIC_STATUS_MAP[$status] ?? $status;
    }

    private function mapStatusToPSState(string $status): int
    {
        if (!isset(self::STATUS_MAP[$status])) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Unmapped status received: ' . $status,
                2,
                null,
                'MPNotification',
                0,
                true
            );

            return 0;
        }

        return (int) \Configuration::get(self::STATUS_MAP[$status]);
    }

    private function isSupportedStatus(string $status): bool
    {
        return isset(self::STATUS_MAP[$status]);
    }

    private function isDowngrade(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) {
            return false;
        }

        if (in_array($newStatus, self::DEVOLUTION_STATUSES, true)) {
            return false;
        }

        if (!in_array($currentStatus, self::TERMINAL_STATES, true)) {
            return false;
        }

        $allowed = self::ALLOWED_TERMINAL_TRANSITIONS[$currentStatus] ?? [];

        return !in_array($newStatus, $allowed, true);
    }

    private function createOrderFromNotification(array $transaction, string $status, float $totalPaid = 0.0): int
    {
        $cartId = (int) $transaction['cart_id'];
        $cart = new \Cart($cartId);

        if (!$cart->id) {
            return 0;
        }

        if (in_array($status, self::NEGATIVE_STATUSES, true)) {
            return 0;
        }

        $orderTotal = (float) $cart->getOrderTotal(true, \Cart::BOTH);
        if ($totalPaid > 0 && $orderTotal > 0 && $totalPaid < ($orderTotal - 0.01)) {
            \PrestaShopLogger::addLog(
                '[MP Notification] Skipping order creation: paid ' . $totalPaid . ' < total ' . $orderTotal,
                2,
                null,
                'MPNotification',
                0,
                true
            );

            return 0;
        }

        $newStateId = $this->mapStatusToPSState($status);
        if ($newStateId <= 0) {
            $newStateId = (int) \Configuration::get('PS_OS_PREPARATION');
        }

        $paidAmount = $totalPaid > 0 ? $totalPaid : (float) $cart->getOrderTotal();
        $customer = new \Customer((int) $cart->id_customer);
        $module = \Module::getInstanceByName('mercadopago');
        if (!$module || !method_exists($module, 'validateOrder')) {
            throw new NotificationRetryableException('Mercado Pago module is unavailable for order creation');
        }
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);

        $reserved = $this->executeRetryablePersistenceOperation(
            fn (): ?bool => $this->repository->reserveWebhookOrderCreation($transactionId, $cartId),
            'Could not reserve exact transaction ownership'
        );
        if ($reserved !== true) {
            throw new NotificationRetryableException('Could not reserve exact transaction ownership');
        }

        try {
            $module->validateOrder(
                $cartId,
                $newStateId,
                $paidAmount,
                $module->displayName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key,
                null,
                OrderAttemptReference::fromAttempt($transactionId, $cartId)
            );
        } catch (\Throwable $th) {
            \PrestaShopLogger::addLog(
                '[MP Notification] validateOrder failed for cart ' . $cartId . ': ' . $th->getMessage(),
                3,
                null,
                'MPNotification',
                0,
                true
            );
            throw new NotificationRetryableException('Could not create order for cart ' . $cartId, 0, $th);
        }

        $psOrderId = (int) \Order::getIdByCartId($cartId);

        if ($psOrderId > 0) {
            $order = new \Order($psOrderId);
            if (!$order->id || !$this->transactionOwnsOrder($transaction, $psOrderId, $order)) {
                throw new NotificationRetryableException('Could not persist exact transaction ownership');
            }
            \PrestaShopLogger::addLog(
                '[MP Notification] Order ' . $psOrderId . ' created from notification for cart ' . $cartId,
                1,
                null,
                'Order',
                $psOrderId,
                true
            );
        }

        return $psOrderId;
    }

    public function syncOrderPayments(int $psOrderId, array $payments): void
    {
        if ($psOrderId <= 0 || empty($payments)) {
            return;
        }

        $order = new \Order($psOrderId);
        if (!$order->id) {
            return;
        }

        $orderPayments = $order->getOrderPaymentCollection();

        if (count($payments) === 1) {
            $payment = $payments[0];
            $paymentId = (string) ($payment['id'] ?? '');
            $existingPayment = null;
            $firstPayment = null;
            $unassignedPayment = null;
            foreach ($orderPayments as $orderPayment) {
                /** @var \OrderPayment $orderPayment */
                $firstPayment ??= $orderPayment;
                if ((string) $orderPayment->transaction_id === $paymentId) {
                    $existingPayment = $orderPayment;
                    break;
                }
                if ((string) $orderPayment->transaction_id === '') {
                    $unassignedPayment ??= $orderPayment;
                }
            }
            if ($existingPayment === null) {
                $existingPayment = $unassignedPayment;
                if ($existingPayment === null && count($orderPayments) === 1) {
                    $existingPayment = $firstPayment;
                }
            }

            if ($paymentId === '' || ($existingPayment === null && count($orderPayments) === 0)) {
                throw new NotificationRetryableException('Could not locate PrestaShop payment to synchronize');
            }
            if ($existingPayment !== null) {
                $existingPayment->transaction_id = $paymentId;
                $existingPayment->amount = (float) ($payment['transaction_amount'] ?? $existingPayment->amount);
                if ($existingPayment->update() !== true) {
                    throw new NotificationRetryableException('Could not persist PrestaShop payment synchronization');
                }
                $this->linkPaymentToInvoices($psOrderId, (int) ($existingPayment->id ?? 0));
                \Cache::clean('order_invoice_paid_*');

                return;
            }
            // A confirmed id absent from assigned receipts uses the transactional append path.
        }

        if (\Db::getInstance()->execute('START TRANSACTION') !== true) {
            throw new NotificationRetryableException('Could not start PrestaShop payment synchronization');
        }
        try {
            $paymentsById = [];
            $unassignedPayments = [];
            foreach ($orderPayments as $existingPayment) {
                /** @var \OrderPayment $existingPayment */
                $existingPaymentId = (string) $existingPayment->transaction_id;
                if ($existingPaymentId === '') {
                    $unassignedPayments[] = $existingPayment;
                    continue;
                }
                $paymentsById[$existingPaymentId] = $existingPayment;
            }
            foreach ($payments as $payment) {
                $paymentId = (string) ($payment['id'] ?? '');
                if ($paymentId === '') {
                    continue;
                }
                $amount = (float) ($payment['transaction_amount'] ?? 0);
                if (isset($paymentsById[$paymentId])) {
                    $existingPayment = $paymentsById[$paymentId];
                    $existingPayment->amount = $amount;
                    if ($existingPayment->update() !== true) {
                        throw new NotificationRetryableException('Could not persist PrestaShop payment synchronization');
                    }
                    $this->linkPaymentToInvoices($psOrderId, (int) ($existingPayment->id ?? 0));
                    continue;
                }
                if ($unassignedPayments !== []) {
                    $existingPayment = array_shift($unassignedPayments);
                    $existingPayment->transaction_id = $paymentId;
                    $existingPayment->amount = $amount;
                    if ($existingPayment->update() !== true) {
                        throw new NotificationRetryableException('Could not persist PrestaShop payment synchronization');
                    }
                    $this->linkPaymentToInvoices($psOrderId, (int) ($existingPayment->id ?? 0));
                    continue;
                }
                $newPayment = new \OrderPayment();
                $newPayment->order_reference = $order->reference;
                $newPayment->id_currency = $order->id_currency;
                $newPayment->amount = $amount;
                $newPayment->payment_method = 'Mercado Pago';
                $newPayment->transaction_id = $paymentId;
                if ($newPayment->add() !== true) {
                    throw new NotificationRetryableException('Could not persist PrestaShop payment synchronization');
                }
                $this->linkPaymentToInvoices($psOrderId, (int) $newPayment->id);
            }
            if (\Db::getInstance()->execute('COMMIT') !== true) {
                throw new NotificationRetryableException('Could not commit PrestaShop payment synchronization');
            }
            \Cache::clean('order_invoice_paid_*');
        } catch (\Throwable $th) {
            \Db::getInstance()->execute('ROLLBACK');
            throw $th;
        }
    }

    private function syncConfirmedMerchantOrderPayments(int $psOrderId, array $confirmedPayments): void
    {
        $financialPayments = [];
        $confirmedById = [];
        foreach ($confirmedPayments as $payment) {
            $id = (string) $payment['id'];
            $status = $this->normalizeStatus((string) ($payment['status'] ?? ''));
            $confirmedById[$id] = $status;
            if ($status === 'processed') {
                $financialPayments[$id] = $payment;
            }
        }
        $order = new \Order($psOrderId);
        if ($order->id) {
            foreach ($order->getOrderPaymentCollection() as $localPayment) {
                /** @var \OrderPayment $localPayment */
                $id = (string) $localPayment->transaction_id;
                $status = $confirmedById[$id] ?? null;
                if ($status === null || $status === 'processed') {
                    continue;
                }
                $paymentMethod = (string) ($localPayment->payment_method ?? '');
                $isModuleReceipt = $paymentMethod === 'Mercado Pago'
                    || ($paymentMethod !== '' && $paymentMethod === (string) $order->payment);
                $preserveReceipt = in_array($status, self::DEVOLUTION_STATUSES, true)
                    || (int) ($localPayment->id_employee ?? 0) > 0
                    || !$isModuleReceipt;
                $amount = $preserveReceipt ? (float) $localPayment->amount : 0.0;
                $financialPayments[$id] = ['id' => $id, 'transaction_amount' => $amount];
            }
        }
        if ($financialPayments !== []) {
            $this->syncOrderPayments($psOrderId, array_values($financialPayments));
        }
    }

    private function linkPaymentToInvoices(int $psOrderId, int $orderPaymentId): void
    {
        if (!$this->repository->linkOrderPaymentToInvoices($psOrderId, $orderPaymentId)) {
            throw new NotificationRetryableException('Could not persist PrestaShop invoice-payment association');
        }
    }

    public function resolveAggregatedStatus(array $payments, float $orderTotal): string
    {
        $processedTotal = 0.0;
        $pendingTotal = 0.0;
        $lastStatus = '';

        foreach ($payments as $payment) {
            $status = $this->normalizeStatus((string) ($payment['status'] ?? ''));
            $lastStatus = $status ?: $lastStatus;
            $txAmount = (float) (
                $payment['transaction_details']['total_paid_amount']
                ?? $payment['transaction_amount']
                ?? 0
            );
            $coupon = (float) ($payment['coupon_amount'] ?? 0);

            if ($status === 'processed') {
                $processedTotal += $txAmount + $coupon;
            } elseif (in_array($status, ['processing', 'action_required', 'in_review'], true)) {
                $pendingTotal += (float) ($payment['transaction_amount'] ?? 0);
            }
        }

        if ($orderTotal > 0 && $processedTotal + self::MERCHANT_ORDER_AMOUNT_TOLERANCE >= $orderTotal) {
            return 'processed';
        }

        if ($orderTotal > 0 && $pendingTotal + self::MERCHANT_ORDER_AMOUNT_TOLERANCE >= $orderTotal) {
            return 'processing';
        }

        if ($orderTotal > 0 && $processedTotal > 0
            && $processedTotal < $orderTotal - self::MERCHANT_ORDER_AMOUNT_TOLERANCE
            && $lastStatus === 'processed'
        ) {
            return 'processing';
        }

        return $lastStatus;
    }

    public function resolveAggregatedAmountForStatus(array $payments, string $aggregatedStatus): float
    {
        if ($this->normalizeStatus($aggregatedStatus) === 'processed') {
            $payments = array_filter(
                $payments,
                fn (array $payment): bool => $this->normalizeStatus((string) ($payment['status'] ?? '')) === 'processed'
            );
        }

        return $this->resolveAggregatedAmount($payments);
    }

    public function resolveAggregatedAmount(array $payments): float
    {
        $total = 0.0;
        foreach ($payments as $payment) {
            $status = $this->normalizeStatus((string) ($payment['status'] ?? ''));
            if ($status === 'processed') {
                $total += (float) (
                    $payment['transaction_details']['total_paid_amount']
                    ?? $payment['transaction_amount']
                    ?? 0
                );
                $total += (float) ($payment['coupon_amount'] ?? 0);
            } elseif (in_array($status, ['processing', 'action_required'], true)) {
                $total += (float) ($payment['transaction_amount'] ?? 0);
            }
        }

        return $total;
    }

    /**
     * Whether $currentStateId is a valid starting point for this notification to transition
     * the order from (PPSP-1604). Native PS_OS_PREPARATION is accepted as a narrow, additional
     * case only when the current transaction is linked to the same cart and PrestaShop order.
     * Checkout falls back to PS_OS_PREPARATION when MERCADOPAGO_STATUS_7 is not configured;
     * without this carve-out, the later approved-payment notification is skipped because the
     * native state has module_name !== 'mercadopago'.
     *
     * This is deliberately narrower than the analogous v4.x fix (PPSP-1575): it only whitelists
     * PS_OS_PREPARATION, the one native state this module itself can put an order into as a side
     * effect of its own missing configuration — not the broader "seller manually moved the order
     * to any native payment state" policy from PPSP-1575, which needs a business-defined matrix
     * of native states x expected transitions (see ticket DoR) before it can be ported here.
     *
     * @return bool
     */
    private function isCurrentStateAllowed(array $transaction, int $currentStateId, int $psOrderId, \Order $order): bool
    {
        if ($currentStateId <= 0) {
            return true;
        }

        $state = \Db::getInstance()->getRow(
            'SELECT `module_name` FROM `' . _DB_PREFIX_ . 'order_state`'
            . ' WHERE `id_order_state` = ' . $currentStateId,
            false
        );

        if ($state && $state['module_name'] === 'mercadopago') {
            return true;
        }

        $backOrder = \Db::getInstance()->getRow(
            'SELECT `id_order_state` FROM `' . _DB_PREFIX_ . 'order_state_lang`'
            . ' WHERE `template` = \'outofstock\''
            . ' AND `id_order_state` = ' . $currentStateId,
            false
        );

        if (!empty($backOrder)) {
            return true;
        }

        if ($currentStateId === (int) \Configuration::get('PS_OS_PREPARATION')) {
            return $this->transactionOwnsOrder($transaction, $psOrderId, $order);
        }

        return false;
    }

    private function transactionOwnsOrder(array $transaction, int $psOrderId, \Order $order): bool
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);
        $cartId = (int) ($transaction['cart_id'] ?? 0);
        if ($transactionId <= 0 || $cartId <= 0 || $psOrderId <= 0) {
            return false;
        }

        if ($this->hasExactTransactionOrderLink($transaction, $psOrderId)) {
            return true;
        }

        $storedOrderId = (int) ($transaction['merchant_order_id'] ?? 0);
        if ($storedOrderId > 0
            || (int) \Order::getIdByCartId($cartId) !== $psOrderId
            || !$this->wasOrderCreatedByThisModule($order)
            || !OrderAttemptReference::matches($order, $transactionId, $cartId)
        ) {
            return false;
        }

        $webhookReserved = $this->executeRetryablePersistenceOperation(
            fn (): ?bool => $this->repository->isWebhookOrderCreationReserved($transactionId, $cartId),
            'Could not verify transaction order-creation reservation'
        );
        if ($webhookReserved === null) {
            throw new NotificationRetryableException('Could not verify transaction order-creation reservation');
        }

        if (!$webhookReserved) {
            $checkoutReserved = $this->executeRetryablePersistenceOperation(
                fn (): ?bool => $this->repository->isOrderCreationReserved($transactionId, $cartId),
                'Could not verify transaction order-creation reservation'
            );
            if ($checkoutReserved === null) {
                throw new NotificationRetryableException('Could not verify transaction order-creation reservation');
            }

            if (!$checkoutReserved) {
                return false;
            }
        }

        $linked = $this->executeRetryablePersistenceOperation(
            fn (): ?bool => $this->repository->linkMerchantOrderId(
                $transactionId,
                $cartId,
                $psOrderId
            ),
            'Could not persist transaction ownership'
        );
        if ($linked === null) {
            throw new NotificationRetryableException('Could not persist transaction ownership');
        }

        $isLinked = $this->executeRetryablePersistenceOperation(
            fn (): ?bool => $this->repository->isTransactionLinkedToOrder(
                $transactionId,
                $cartId,
                $psOrderId
            ),
            'Could not verify persisted transaction ownership'
        );
        if ($isLinked === null) {
            throw new NotificationRetryableException('Could not verify persisted transaction ownership');
        }

        return $isLinked;
    }

    private function hasExactTransactionOrderLink(array $transaction, int $psOrderId): bool
    {
        $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);
        $cartId = (int) ($transaction['cart_id'] ?? 0);
        if ($transactionId <= 0 || $cartId <= 0 || $psOrderId <= 0) {
            return false;
        }

        $isLinked = $this->executeRetryablePersistenceOperation(
            fn (): ?bool => $this->repository->isTransactionLinkedToOrder(
                $transactionId,
                $cartId,
                $psOrderId
            ),
            'Could not verify transaction ownership'
        );
        if ($isLinked === null) {
            throw new NotificationRetryableException('Could not verify transaction ownership');
        }

        return $isLinked;
    }

    /**
     * Matching by cart_id alone is not proof of ownership: the cart can be
     * finalized by another payment module before a late/retried MP
     * notification arrives (PPSP-1604). `orders.module` records which module
     * actually created the order, so the auto-link is only allowed when this
     * module did.
     */
    private function wasOrderCreatedByThisModule(\Order $order): bool
    {
        return $order->id > 0 && $order->module === 'mercadopago';
    }

    /**
     * @return mixed
     */
    private function executeRetryablePersistenceOperation(callable $operation, string $message)
    {
        try {
            return $operation();
        } catch (\Throwable $th) {
            throw new NotificationRetryableException($message, 0, $th);
        }
    }
}
