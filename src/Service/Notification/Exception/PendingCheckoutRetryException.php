<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
declare(strict_types=1);

namespace MercadoPago\Service\Notification\Exception;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The remote payment state is valid and durable, but checkout still owns the
 * exact pending attempt and must finish local order creation before a webhook
 * can mutate the PrestaShop order.
 */
class PendingCheckoutRetryException extends NotificationRetryableException
{
}
