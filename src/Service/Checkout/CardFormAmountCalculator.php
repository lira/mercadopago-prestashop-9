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

namespace MercadoPago\Service\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CardFormAmountCalculator
{
    /** @var callable|null */
    private $previewCartFactory;

    public function __construct(?callable $previewCartFactory = null)
    {
        $this->previewCartFactory = $previewCartFactory;
    }

    /**
     * Quote the same Cart Rule that CardOrderService applies on submit without
     * writing the rule or its association to the database.
     */
    public function calculate(\Cart $cart, float $discountPercent): float
    {
        if ((int) $cart->id <= 0) {
            if ($discountPercent <= 0) {
                return (float) $cart->getOrderTotal(true);
            }

            throw new \LogicException('Card amount preview requires a persisted cart');
        }

        $featureFlag = \Configuration::get('PS_CART_RULE_FEATURE_ACTIVE');
        $shippingCachePattern = 'getPackageShippingCost_' . (int) $cart->id . '_*';
        \Cart::resetStaticCache();
        \Cache::clean($shippingCachePattern);

        try {
            // Configuration::set() only changes PrestaShop's request-local cache.
            // The real CartRule created on submit enables the same flag persistently.
            if ($discountPercent > 0 && !(bool) $featureFlag) {
                \Configuration::set('PS_CART_RULE_FEATURE_ACTIVE', '1');
            }

            $previewCart = $this->createPreviewCart($cart, $discountPercent);

            return (float) $previewCart->getOrderTotal(true);
        } finally {
            if ($discountPercent > 0 && !(bool) $featureFlag) {
                \Configuration::set('PS_CART_RULE_FEATURE_ACTIVE', $featureFlag);
            }

            // Never leak a discounted shipping quote to other payment options
            // rendered later in the same checkout request.
            \Cart::resetStaticCache();
            \Cache::clean($shippingCachePattern);
        }
    }

    private function createPreviewCart(\Cart $cart, float $discountPercent): \Cart
    {
        if ($this->previewCartFactory !== null) {
            $previewCart = ($this->previewCartFactory)($cart, $discountPercent);
            if (!$previewCart instanceof \Cart) {
                throw new \UnexpectedValueException('Card amount preview factory must return a Cart');
            }

            return $previewCart;
        }

        $languageId = property_exists($cart, 'id_lang') ? (int) $cart->id_lang : null;
        $previewCart = new CardDiscountPreviewCart((int) $cart->id, $languageId);

        return $previewCart->configureFrom($cart, $discountPercent);
    }
}
