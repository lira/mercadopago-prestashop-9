<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is a Registered Trademark & Property of PrestaShop SA
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

namespace MercadoPago\Repository;

use MercadoPago\Helper\PaymentMethodDiscountRule;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartRuleRepository
{
    /**
     * Hash the primary-database cart state that can change the checkout payload.
     *
     * The module-owned discount rule and the cart update timestamp are excluded:
     * retries deliberately replace that rule and PrestaShop touches date_upd when
     * associations change. Products, store vouchers and address contents remain
     * part of the token so a post-API order cannot silently use another cart state.
     */
    public function getFinancialStateToken(\Cart $cart): string
    {
        $cartId = (int) $cart->id;
        if ($cartId <= 0) {
            throw new \InvalidArgumentException('A persisted cart is required for a financial snapshot');
        }

        $db = \Db::getInstance(true);
        $cartRow = $db->getRow(
            'SELECT c.* FROM `' . _DB_PREFIX_ . 'cart` c WHERE c.`id_cart` = ' . $cartId,
            false
        );
        $products = $db->executeS(
            'SELECT cp.* FROM `' . _DB_PREFIX_ . 'cart_product` cp'
            . ' WHERE cp.`id_cart` = ' . $cartId
            . ' ORDER BY cp.`id_product`, cp.`id_product_attribute`, cp.`id_customization`, cp.`id_address_delivery`',
            true,
            false
        );
        $ownedRuleCodes = array_map(
            static fn (string $code): string => '\'' . pSQL($code) . '\'',
            PaymentMethodDiscountRule::codesForCart($cartId)
        );
        $rules = $db->executeS(
            'SELECT ccr.`id_cart_rule`, cr.* FROM `' . _DB_PREFIX_ . 'cart_cart_rule` ccr'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule` cr ON cr.`id_cart_rule` = ccr.`id_cart_rule`'
            . ' WHERE ccr.`id_cart` = ' . $cartId
            . ' AND cr.`code` NOT IN (' . implode(', ', $ownedRuleCodes) . ')'
            . ' ORDER BY ccr.`id_cart_rule`',
            true,
            false
        );

        if (!is_array($cartRow) || !is_array($products) || !is_array($rules)) {
            throw new \RuntimeException('Could not read the cart financial state');
        }

        unset($cartRow['date_upd']);
        $addressIds = array_values(array_unique(array_filter([
            (int) ($cartRow['id_address_delivery'] ?? 0),
            (int) ($cartRow['id_address_invoice'] ?? 0),
        ])));
        $addresses = [];
        if ($addressIds !== []) {
            $addresses = $db->executeS(
                'SELECT a.* FROM `' . _DB_PREFIX_ . 'address` a'
                . ' WHERE a.`id_address` IN (' . implode(', ', $addressIds) . ')'
                . ' ORDER BY a.`id_address`',
                true,
                false
            );
            if (!is_array($addresses)) {
                throw new \RuntimeException('Could not read the cart address state');
            }
        }

        return hash(
            'sha256',
            json_encode(
                [$cartRow, $products, $rules, $addresses],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
            )
        );
    }

    /**
     * Read from the primary connection so a preceding cart-scoped lock observes
     * the most recently created rule even when a database replica is configured.
     */
    public function findIdByCode(string $code): int
    {
        $db = \Db::getInstance(true);
        $id = $db->getValue(
            'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule`'
            . ' WHERE `code` = \'' . pSQL($code) . '\''
            . ' ORDER BY `id_cart_rule` ASC',
            false
        );

        if ($id === false && $db->getNumberError() !== 0) {
            throw new \RuntimeException('Could not read the Mercado Pago cart rule');
        }

        return (int) $id;
    }

    /**
     * Return every row that uses the deterministic payment-method discount code.
     *
     * The code column is not unique, so older concurrent submissions may have
     * left duplicate rows. Zero-discount reconciliation must neutralize all of
     * them instead of trusting an arbitrary first match.
     *
     * @return int[]
     */
    public function findIdsByCode(string $code): array
    {
        $db = \Db::getInstance(true);
        $rows = $db->executeS(
            'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule`'
            . ' WHERE `code` = \'' . pSQL($code) . '\''
            . ' ORDER BY `id_cart_rule` ASC',
            true,
            false
        );

        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the Mercado Pago cart rules');
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id_cart_rule'] ?? 0),
            $rows
        ))));
    }

    /**
     * Find every current or legacy payment-method discount row for a cart.
     *
     * Both ownership codes are queried together so reconciliation cannot see a
     * partially migrated state between independent database reads.
     *
     * @return int[]
     */
    public function findIdsForCart(int $cartId): array
    {
        $quotedCodes = array_map(
            static fn (string $code): string => '\'' . pSQL($code) . '\'',
            PaymentMethodDiscountRule::codesForCart($cartId)
        );
        $db = \Db::getInstance(true);
        $rows = $db->executeS(
            'SELECT `id_cart_rule` FROM `' . _DB_PREFIX_ . 'cart_rule`'
            . ' WHERE `code` IN (' . implode(', ', $quotedCodes) . ')'
            . ' ORDER BY `id_cart_rule` ASC',
            true,
            false
        );

        if (!is_array($rows)) {
            throw new \RuntimeException('Could not read the Mercado Pago cart rules');
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id_cart_rule'] ?? 0),
            $rows
        ))));
    }
}
