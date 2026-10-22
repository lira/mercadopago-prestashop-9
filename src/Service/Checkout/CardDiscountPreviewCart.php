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

use MercadoPago\Helper\PaymentMethodDiscountRule;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Read-only cart projection used to quote the Card discount with PrestaShop's
 * own calculator. The synthetic rule is never saved or associated with the
 * shopper's real cart.
 */
class CardDiscountPreviewCart extends \Cart
{
    private float $discountPercent = 0.0;

    private string $discountCode = '';

    /** @var string[] */
    private array $discountCodes = [];

    /**
     * Copy request-time cart state that can affect totals but may not have
     * been reloaded by the parent constructor yet.
     */
    public function configureFrom(\Cart $sourceCart, float $discountPercent): self
    {
        $properties = [
            'id',
            'id_shop_group',
            'id_shop',
            'id_address_delivery',
            'id_address_invoice',
            'id_carrier',
            'id_currency',
            'id_customer',
            'id_guest',
            'id_lang',
            'recyclable',
            'gift',
            'gift_message',
            'delivery_option',
            'allow_seperated_package',
        ];

        foreach ($properties as $property) {
            if (property_exists($sourceCart, $property) && property_exists($this, $property)) {
                $this->{$property} = $sourceCart->{$property};
            }
        }

        $this->discountPercent = $discountPercent;
        $this->discountCode = PaymentMethodDiscountRule::codeForCart((int) $sourceCart->id);
        $this->discountCodes = PaymentMethodDiscountRule::codesForCart((int) $sourceCart->id);

        return $this;
    }

    /**
     * @param int|null $filter
     * @param bool $autoAdd
     * @param bool $useOrderPrices
     *
     * @return array
     */
    public function getCartRules(
        $filter = \CartRule::FILTER_ACTION_ALL,
        $autoAdd = true,
        $useOrderPrices = false,
    ): array {
        // Never let a read-only quote trigger CartRule::autoAddToCart().
        $cartRules = parent::getCartRules($filter, false, $useOrderPrices);
        if (!is_array($cartRules)) {
            throw new \RuntimeException('Unable to load cart rules for Card amount preview');
        }

        if (!$this->supportsReductionFilter($filter)) {
            return $cartRules;
        }

        $cartRules = array_values(array_filter(
            $cartRules,
            function (array $cartRule): bool {
                $object = $cartRule['obj'] ?? null;
                $code = is_object($object) && property_exists($object, 'code')
                    ? (string) $object->code
                    : (string) ($cartRule['code'] ?? '');

                return !in_array($code, $this->discountCodes, true);
            }
        ));
        if ($this->discountPercent > 0) {
            $cartRules[] = $this->buildSyntheticDiscountRule();
        }

        return $cartRules;
    }

    /**
     * @param int|null $filter
     */
    private function supportsReductionFilter($filter): bool
    {
        return in_array(
            $filter,
            [
                \CartRule::FILTER_ACTION_ALL,
                \CartRule::FILTER_ACTION_ALL_NOCAP,
                \CartRule::FILTER_ACTION_REDUCTION,
            ],
            true
        );
    }

    private function buildSyntheticDiscountRule(): array
    {
        $cartRule = new \CartRule();
        $cartRule->id = 0;
        PaymentMethodDiscountRule::configure(
            $cartRule,
            (int) $this->id,
            $this->discountPercent,
            (int) $this->id_customer
        );

        return [
            'id_cart_rule' => 0,
            'code' => $this->discountCode,
            'priority' => PaymentMethodDiscountRule::LOWEST_PRIORITY,
            'free_shipping' => 0,
            'reduction_percent' => $this->discountPercent,
            'reduction_amount' => 0,
            'gift_product' => 0,
            'obj' => $cartRule,
            'value_real' => 0.0,
            'value_tax_exc' => 0.0,
            'description' => '',
        ];
    }
}
