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
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace MercadoPago\Service\Preference;

use MercadoPago\Repository\PreferenceGuardRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PreferenceService extends AbstractPreferenceService
{
    public function __construct(\Context $context, private PreferenceGuardRepository $guardStorage)
    {
        parent::__construct($context);
        $this->checkout = 'preference';
    }

    /**
     * @param \Cart $cart
     *
     * @return array
     */
    public function createPreference($cart)
    {
        return $this->withCartContext(
            $cart,
            fn (): array => $this->createPreferenceForCart($cart)
        );
    }

    private function createPreferenceForCart($cart): array
    {
        $paymentMethods = $this->getPaymentMethods();

        $payload = array_merge(
            $this->getCommonPreference($cart),
            [
                'items' => $this->getItemsForPreference($cart),
                'payer' => $this->getPayer($cart),
                'back_urls' => $this->getBackUrls($cart),
                'auto_return' => $this->getAutoReturn(),
                'binary_mode' => $this->getBinaryMode(),
                'payment_methods' => $this->getPaymentOptions(),
                'metadata' => $this->getMetadata($cart),
            ]
        );

        if (!empty($paymentMethods)) {
            $payload['payment_methods'] = $paymentMethods;
        }

        if ($this->getExpirationStatus()) {
            $payload['expires'] = true;
            $payload['expiration_date_to'] = $this->getExpirationDate();
        }

        return $this->createGuardedPreference($cart, $payload);
    }

    /**
     * @param \Cart $cart
     *
     * @return array
     */
    public function createWalletPreference($cart)
    {
        return $this->withCartContext(
            $cart,
            fn (): array => $this->createWalletPreferenceForCart($cart)
        );
    }

    private function createWalletPreferenceForCart($cart): array
    {
        $payload = array_merge(
            $this->getCommonPreference($cart),
            [
                'items' => $this->getItemsForPreference($cart),
                'payer' => $this->getPayer($cart),
                'back_urls' => $this->getWalletBackUrls($cart),
                'auto_return' => $this->getAutoReturn(),
                'binary_mode' => $this->getBinaryMode(),
                'payment_methods' => $this->getPaymentOptions(),
                'metadata' => $this->getWalletMetadata(),
                'purpose' => 'wallet_purchase',
            ]
        );

        if ($this->getExpirationStatus()) {
            $payload['expires'] = true;
            $payload['expiration_date_to'] = $this->getExpirationDate();
        }

        return $this->createGuardedPreference($cart, $payload);
    }

    /** Capture the exact outbound financial identity before calling Mercado Pago. */
    private function createGuardedPreference($cart, array $payload): array
    {
        $snapshot = $this->snapshotForCart($cart, $payload['items']);
        $result = $this->mpApi->createPreference($payload);
        $result['_preference_snapshot'] = $snapshot;

        return $result;
    }

    /**
     * Capture the item payload and the cart conditions that explain its total.
     *
     * @param \Cart $cart
     */
    public function snapshotForCart(\Cart $cart, ?array $items = null): array
    {
        return $this->withCartContext($cart, function () use ($cart, $items): array {
            $currency = new \Currency((int) $cart->id_currency);
            $conditions = [
                'discount' => PreferenceSnapshot::money($cart->getOrderTotal(true, \Cart::ONLY_DISCOUNTS), $currency->iso_code),
                'shipping' => PreferenceSnapshot::money($cart->getOrderTotal(true, \Cart::ONLY_SHIPPING), $currency->iso_code),
                'wrapping' => PreferenceSnapshot::money($cart->getOrderTotal(true, \Cart::ONLY_WRAPPING), $currency->iso_code),
                'carrier' => (int) $cart->id_carrier,
                'gift' => (bool) $cart->gift,
                'rules' => [],
            ];
            foreach ($cart->getCartRules(\CartRule::FILTER_ACTION_ALL, false) as $rule) {
                $conditions['rules'][] = [
                    'id' => (int) $rule['id_cart_rule'],
                    'amount' => PreferenceSnapshot::money(
                        $rule['value_real'] ?? $rule['value'] ?? 0,
                        $currency->iso_code
                    ),
                ];
            }

            return PreferenceSnapshot::create(
                $items ?? $this->getItemsForPreference($cart),
                $cart->getProducts(),
                (int) $cart->id_shop,
                (int) $cart->id_currency,
                (string) $currency->iso_code,
                (float) $cart->getOrderTotal(true, \Cart::BOTH),
                $conditions
            );
        });
    }

    /** Recalculate a cart under its own request context and restore it afterwards. */
    public function withCartContext(\Cart $cart, callable $operation): mixed
    {
        $context = $this->context;
        $previous = [
            $context->cart ?? null,
            $context->currency ?? null,
            $context->shop ?? null,
            $context->language ?? null,
            $context->customer ?? null,
            $context->country ?? null,
        ];
        try {
            $context->cart = $cart;
            $context->customer = new \Customer((int) $cart->id_customer);
            $addressField = (string) \Configuration::get('PS_TAX_ADDRESS_TYPE');
            $addressId = $addressField === 'id_address_invoice'
                ? (int) $cart->id_address_invoice
                : (int) $cart->id_address_delivery;
            $address = new \Address($addressId);
            $context->country = new \Country((int) $address->id_country);
            $context->currency = new \Currency((int) $cart->id_currency);
            $context->shop = new \Shop((int) $cart->id_shop);
            $context->language = new \Language((int) $cart->id_lang);
            $this->resetCartCalculationCaches();

            return $operation($context);
        } finally {
            [$context->cart, $context->currency, $context->shop, $context->language,
                $context->customer, $context->country] = $previous;
            $this->resetCartCalculationCaches();
        }
    }

    private function resetCartCalculationCaches(): void
    {
        \Cart::resetStaticCache();
        \Product::resetStaticCache();
        \SpecificPrice::resetStaticCache();
        \CartRule::cleanCache();
    }

    /** Persist the preference and its immutable snapshot under the cart lock. */
    public function saveGuardedPreference(\Cart $cart, array $response, array $snapshot): int
    {
        $preferenceId = (string) ($response['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $preferenceId) !== 1
            || empty($snapshot['snapshot_hash'])
            || empty($snapshot['snapshot_json'])
            || !isset($snapshot['snapshot_total'])
        ) {
            throw new \DomainException('Invalid preference snapshot');
        }

        return $this->withOrderCreationLock((int) $cart->id, function () use ($cart, $response, $snapshot, $preferenceId): int {
            $this->transactionRepository->beginCartOrderTransactionAndLock((int) $cart->id);
            try {
                \Cart::resetStaticCache();
                $freshCart = new \Cart((int) $cart->id);
                $transactionId = $this->withCartContext($freshCart, function () use ($freshCart, $response, $snapshot, $preferenceId): int {
                    $current = $this->snapshotForCart($freshCart);
                    if (!hash_equals((string) $snapshot['snapshot_hash'], (string) $current['snapshot_hash'])
                        || $snapshot['snapshot_total'] !== $current['snapshot_total']
                    ) {
                        throw new \DomainException('Cart changed while preference was being created');
                    }
                    $transactionId = $this->saveCreateOrderData($freshCart, $response);
                    if ($transactionId <= 0) {
                        throw new \RuntimeException('Could not persist preference transaction');
                    }
                    $this->guardStorage->saveSnapshot($transactionId, $preferenceId, $snapshot);

                    return $transactionId;
                });
                if ($transactionId <= 0) {
                    throw new \RuntimeException('Could not persist preference transaction');
                }
                $this->transactionRepository->commitCartOrderTransaction();

                return $transactionId;
            } catch (\Throwable $cause) {
                $this->transactionRepository->rollbackCartOrderTransaction();
                throw $cause;
            }
        });
    }

    /**
     * @param \Cart $cart
     *
     * @return array
     */
    private function getMetadata($cart)
    {
        $checkoutType = (bool) \Configuration::get('MERCADOPAGO_STANDARD_MODAL') ? 'modal' : 'redirect';

        return [
            'checkout' => 'preference',
            'checkout_type' => $checkoutType,
        ];
    }

    /**
     * @return array
     */
    private function getWalletMetadata(): array
    {
        return [
            'checkout' => 'custom',
            'checkout_type' => 'wallet_button',
        ];
    }

    /**
     * Same trust and scheme contract as AbstractPreferenceService::getReturnUrl()
     * (PPSP-1603, CWE-601): these back_urls redirect the customer's browser after checkout.
     *
     * @param \Cart $cart
     *
     * @return array
     *
     * @throws \UnexpectedValueException
     */
    private function getWalletBackUrls(\Cart $cart): array
    {
        $base = $this->getSiteUrl() .
            '?fc=module&module=mercadopago&controller=walletbutton&cart_id=' . (int) $cart->id;

        return [
            'success' => $base . '&typeReturn=success',
            'failure' => $base . '&typeReturn=failure',
            'pending' => $base . '&typeReturn=pending',
        ];
    }
}
