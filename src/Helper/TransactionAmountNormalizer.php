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

final class TransactionAmountNormalizer
{
    private const ZERO_DECIMAL_SITE_IDS = ['MCO', 'MLC'];

    /**
     * @param mixed $siteId
     */
    public static function usesZeroDecimalPrecision($siteId): bool
    {
        $normalizedSiteId = strtoupper(trim((string) $siteId));

        return in_array($normalizedSiteId, self::ZERO_DECIMAL_SITE_IDS, true);
    }

    /**
     * @param mixed $siteId
     */
    public static function normalize(float $amount, $siteId): float
    {
        return self::usesZeroDecimalPrecision($siteId)
            ? (float) \Tools::ps_round($amount, 0)
            : $amount;
    }
}
