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

use MercadoPago\Repository\PreferenceGuardRepository;
use MercadoPago\Service\Notification\Exception\NotificationSameStateException;
use MercadoPago\Service\Notification\Exception\NotificationSkippedException;
use MercadoPago\Service\Notification\PaymentNotificationService;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Browser hints cannot authorize an order; use the notification's canonical path. */
final class PreferenceReturnService
{
    public function __construct(private PaymentNotificationService $notifications, private PreferenceGuardRepository $storage)
    {
    }

    public function complete(int $cartId, string $preferenceId, string $paymentId, string $secureKey): \Order
    {
        if ($cartId <= 0 || preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $preferenceId) !== 1) {
            throw new \DomainException('Invalid return preference');
        }
        $transaction = $this->storage->find($preferenceId);
        if ($transaction === null || (int) $transaction['cart_id'] !== $cartId) {
            throw new \DomainException('Return preference does not match cart');
        }
        if ($paymentId === '') {
            $paymentId = (string) ($transaction['guard_payment_id'] ?? $transaction['payment_id'] ?? '');
        }
        if (preg_match('/^[0-9]{1,30}$/D', $paymentId) !== 1) {
            throw new NotificationSkippedException('Payment confirmation is pending', 'guard_pending');
        }
        try {
            $this->notifications->processPaymentNotification($paymentId, $secureKey, $cartId, [], [], $preferenceId);
        } catch (NotificationSameStateException) {
            // Canonical verification and exact ownership checks have already succeeded.
        }
        $transaction = $this->storage->find($preferenceId);
        if (($transaction['guard_status'] ?? '') !== 'linked' || ($transaction['guard_payment_id'] ?? '') !== $paymentId) {
            throw new NotificationSkippedException('Payment requires manual review', 'preference_guard');
        }

        return new \Order((int) $transaction['guard_order_id']);
    }
}
