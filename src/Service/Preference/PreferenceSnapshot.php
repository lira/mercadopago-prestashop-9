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

/** Canonical, non-personal financial identity of the exact outbound payload. */
final class PreferenceSnapshot
{
    public static function money(mixed $value, string $currency): string
    {
        $amount = self::numericAmount($value);

        return number_format(\Tools::ps_round($amount, in_array($currency, ['CLP', 'COP'], true) ? 0 : 2), 6, '.', '');
    }

    private static function numericAmount(mixed $value): float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value) || !is_finite((float) $value)
        ) {
            throw new \DomainException('Invalid snapshot amount');
        }

        return (float) $value;
    }

    public static function create(array $items, array $products, int $shopId, int $currencyId, string $currency, float $cartTotal, array $conditions): array
    {
        $lines = [];
        $total = 0.0;
        foreach ($items as $item) {
            // Preserve the exact outbound unit price; round only after quantity is applied.
            $price = number_format(self::numericAmount($item['unit_price'] ?? null), 6, '.', '');
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new \DomainException('Invalid snapshot quantity');
            }
            $synthetic = (string) ($item['title'] ?? '');
            $id = (string) ($item['id'] ?? '');
            if ($id === '' && !in_array($synthetic, ['Discount', 'Shipping', 'Wrapping', 'Difference'], true)) {
                throw new \DomainException('Unknown synthetic preference item');
            }
            $lines[] = ['id' => $id !== '' ? $id : $synthetic, 'quantity' => $quantity, 'unit_price' => $price];
            $total += (float) $price * $quantity;
        }
        $composition = [];
        foreach ($products as $product) {
            $row = [];
            foreach (['id_product', 'id_product_attribute', 'id_customization', 'quantity', 'id_tax_rules_group'] as $key) {
                $row[$key] = (int) ($product[$key] ?? 0);
            }
            foreach (['price', 'price_wt', 'rate', 'reduction_applies', 'reduction_without_tax'] as $key) {
                $row[$key] = number_format((float) ($product[$key] ?? 0), 6, '.', '');
            }
            $composition[] = $row;
        }
        $sort = static function (array &$rows): void {
            usort($rows, static fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        };
        $sort($lines);
        $sort($composition);
        $sort($conditions['rules']);
        $expected = self::money($total, $currency);
        if ($items === [] || $shopId <= 0 || $currencyId <= 0 || (float) $expected <= 0
            || $expected !== self::money($cartTotal, $currency)
        ) {
            throw new \DomainException('Preference payload and cart totals differ');
        }
        $data = ['version' => 1, 'shop' => $shopId, 'currency_id' => $currencyId, 'currency' => $currency,
            'items' => $lines, 'products' => $composition, 'conditions' => $conditions];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['snapshot_total' => $expected, 'snapshot_hash' => hash('sha256', $json),
            'snapshot_json' => $json, 'snapshot_shop' => $shopId, 'snapshot_currency' => $currencyId,
            'snapshot_currency_iso' => $currency];
    }
}
