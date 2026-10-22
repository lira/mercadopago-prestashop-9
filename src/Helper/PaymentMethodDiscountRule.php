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
 * If you did not receive a copy of the license, see the URL above.
 *
 * @author    PrestaShop SA and Contributors
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace MercadoPago\Helper;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class PaymentMethodDiscountRule
{
    private const CODE_PREFIX = 'MPDISCOUNT';

    /** Maximum value accepted by PrestaShop's INT UNSIGNED priority column. */
    public const LOWEST_PRIORITY = 4294967295;

    public static function codeForCart(int $cartId): string
    {
        return self::CODE_PREFIX . hash_hmac(
            'sha256',
            'payment-method-discount:cart:' . $cartId,
            (string) _COOKIE_KEY_
        );
    }

    /**
     * Deterministic code used before cart-rule ownership was hardened.
     *
     * Keep this value available only for migration and cleanup. New rules must
     * always use codeForCart().
     */
    public static function legacyCodeForCart(int $cartId): string
    {
        return self::CODE_PREFIX . $cartId;
    }

    /**
     * @return string[] current code first, followed by codes accepted for cleanup
     */
    public static function codesForCart(int $cartId): array
    {
        return [
            self::codeForCart($cartId),
            self::legacyCodeForCart($cartId),
        ];
    }

    public static function isCodeForCart(string $code, int $cartId): bool
    {
        foreach (self::codesForCart($cartId) as $ownedCode) {
            if (hash_equals($ownedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    public static function configure(
        \CartRule $cartRule,
        int $cartId,
        float $discountPercent,
        int $customerId = 0,
    ): void {
        if (!is_finite($discountPercent) || $discountPercent <= 0 || $discountPercent > 100) {
            throw new \InvalidArgumentException('Invalid payment method discount percentage');
        }
        if ($customerId < 0) {
            throw new \InvalidArgumentException('Invalid payment method discount customer');
        }

        $cartRule->code = self::codeForCart($cartId);
        $cartRule->id_customer = $customerId;
        $cartRule->quantity = 1;
        $cartRule->quantity_per_user = 1;
        $cartRule->priority = self::LOWEST_PRIORITY;
        $cartRule->active = true;
        $cartRule->free_shipping = false;
        $cartRule->reduction_percent = $discountPercent;
        $cartRule->reduction_amount = 0;
        $cartRule->reduction_product = 0;
        $cartRule->reduction_exclude_special = false;
        $cartRule->gift_product = 0;
        $cartRule->gift_product_attribute = 0;
    }
}
