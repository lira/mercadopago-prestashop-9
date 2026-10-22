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

final class ShopUrlHelper
{
    /**
     * Accept a configured shop authority only when it is exactly a hostname with
     * an optional numeric port. Schemes, user-info, paths, queries and fragments
     * are rejected so callers can safely reuse the original value in a URL.
     *
     * @param mixed $domain
     */
    public static function validateAuthority($domain): ?string
    {
        $authority = trim((string) $domain);
        $parts = $authority === '' ? false : parse_url('https://' . $authority);

        if (!is_array($parts)
            || !isset($parts['host'])
            || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] === 0)
        ) {
            return null;
        }

        $expectedAuthority = $parts['host'];
        if (isset($parts['port'])) {
            $expectedAuthority .= ':' . $parts['port'];
        }

        return $authority === $expectedAuthority ? $authority : null;
    }

    public static function getConfiguredSslAuthority(): ?string
    {
        return self::validateAuthority(\ShopUrl::getMainShopDomainSSL());
    }
}
