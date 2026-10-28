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

namespace MercadoPago\Service\Notification;

use MercadoPago\Client\MPApi;
use MercadoPago\Repository\MPTransactionRepository;
use MercadoPago\Repository\PreferenceGuardRepository;
use MercadoPago\Service\Preference\PreferenceGuardService;
use MercadoPago\Service\Preference\PreferenceReturnService;
use MercadoPago\Service\Preference\PreferenceService;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Composition root for request-scoped services in the PrestaShop module. */
final class PaymentNotificationServiceFactory
{
    public static function create(\Context $context): PaymentNotificationService
    {
        $api = new MPApi();
        $transactions = new MPTransactionRepository();
        $guardStorage = new PreferenceGuardRepository();
        $preferences = new PreferenceService($context, $guardStorage);
        $guard = new PreferenceGuardService($api, $transactions, $guardStorage, $preferences);

        return new PaymentNotificationService($api, $transactions, $guard);
    }

    public static function createReturnService(\Context $context): PreferenceReturnService
    {
        return new PreferenceReturnService(self::create($context), new PreferenceGuardRepository());
    }
}
