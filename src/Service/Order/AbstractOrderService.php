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
declare(strict_types=1);

/**
 * 2007-2025 PrestaShop.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @copyright Copyright (c) MercadoPago
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace MercadoPago\Service\Order;

use Customer;
use MercadoPago\Client\MPApi;
use MercadoPago\Helper\PaymentMethodDiscountRule;
use MercadoPago\Helper\ShopUrlHelper;
use MercadoPago\Helper\TransactionAmountNormalizer;
use MercadoPago\Repository\CartRuleRepository;
use MercadoPago\Repository\MPTransactionRepository;
use MercadoPago\Service\Order\Exception\CartRuleApplicationException;
use MercadoPago\Service\Order\Exception\FinancialCartMismatchException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AbstractOrderService
{
    public const ORDER_TYPE_ONLINE = 'online';

    private const TERMINAL_NEGATIVE_PAYMENT_STATUSES = ['failed', 'canceled', 'expired'];

    public $mpApi;

    protected string $checkout = '';

    protected $transactionRepository;

    protected $cartRuleRepository;

    protected $cart_rule;

    /** @var int|null Cart that owns the request-local financial snapshot. */
    private $capturedFinancialCartId;

    /** @var float|null Discount percentage sent in the request. */
    private $capturedDiscountPercent;

    /** @var float|null Amount sent to Mercado Pago. */
    private $capturedTransactionAmount;

    /** @var string|null Hash of items sent to Mercado Pago. */
    private $capturedItemsHash;

    /** @var string|null Primary-database state token captured with the payload. */
    private $capturedFinancialStateToken;

    /** @var int|null Seller resolved before entering the order-creation lock. */
    private $capturedCollectorId;

    /** Request-scoped PrestaShop context supplied by the front controller. */
    protected \Context $context;

    public function __construct(\Context $context)
    {
        $this->context = $context;
        $this->mpApi = new MPApi();
        $this->transactionRepository = new MPTransactionRepository();
        $this->cartRuleRepository = new CartRuleRepository();
    }

    /**
     * Configuration key used to read the discount percentage for this payment method.
     * Override in each concrete service. Default '' disables the discount feature.
     */
    protected function getDiscountConfigKey(): string
    {
        return '';
    }

    /**
     * True when the configured discount for this payment method is > 0.
     */
    protected function isDiscountEnabled(): bool
    {
        $key = $this->getDiscountConfigKey();
        if ($key === '') {
            return false;
        }

        return (float) \Configuration::get($key) > 0;
    }

    /**
     * @param $cart
     *
     * @return array
     */
    public function getCommonMPOrder($cart)
    {
        $mpOrder = [
            'external_reference' => (string) $cart->id,
            'notification_url' => $this->getNotificationUrl($cart),
        ];

        $isTestUser = $this->isTestUser();
        if (!$isTestUser) {
            $mpOrder['sponsor_id'] = $this->getSponsorId();
        }

        return $mpOrder;
    }

    /**
     * Get notification url
     *
     * The configured shop domain is the only trusted source for the localhost guard and
     * the URL itself. The previous strrpos($this->getSiteUrl(), 'localhost') guard read the
     * request's Host header, which let an attacker suppress the payment notification for
     * their own order by sending a Host header containing "localhost" on the checkout
     * request (PPSP-1603, CWE-601). This follows the same trust boundary independently of
     * getSiteUrl() — see getSiteUrl() for the same pattern applied to its own callers.
     *
     * @param $cart
     *
     * @return string|void
     */
    public function getNotificationUrl($cart)
    {
        $customer = new \Customer((int) $cart->id_customer);

        $domain = ShopUrlHelper::getConfiguredSslAuthority();
        $configuredUrl = $domain === null ? false : parse_url('https://' . $domain);
        $host = is_array($configuredUrl) ? ($configuredUrl['host'] ?? null) : null;

        if ($domain === null
            || !is_string($host)
            || $host === ''
            || strcasecmp($host, 'localhost') === 0
        ) {
            return;
        }

        $routeUrl = $this->context->link->getModuleLink(
            'mercadopago',
            'notification',
            [
                'checkout' => $this->checkout,
                'customer' => $customer->secure_key,
                'notification' => 'ipn',
                'source_news' => 'ipn',
            ],
            true
        );
        $route = parse_url($routeUrl);
        $path = is_array($route) ? ($route['path'] ?? '') : '';

        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return;
        }

        $query = isset($route['query']) ? '?' . $route['query'] : '';

        return 'https://' . $domain . $path . $query;
    }

    public function getStatementDescriptor()
    {
        if (\Configuration::get('MERCADOPAGO_INVOICE_NAME') == null) {
            return '';
        }

        return \Configuration::get('MERCADOPAGO_INVOICE_NAME');
    }

    /**
     * Get site url
     *
     * Domain is resolved directly from the current shop's configured ShopUrl instead of
     * the request's Host header, failing closed when no domain is configured (PPSP-1603,
     * CWE-601) — same trust boundary as PPSP-1892 in the v4.x line.
     *
     * @return string
     *
     * @throws \UnexpectedValueException
     */
    public function getSiteUrl()
    {
        $useSsl = \Configuration::get('PS_SSL_ENABLED') || \Tools::usingSecureMode();
        $scheme = $useSsl ? 'https://' : 'http://';
        $domain = $useSsl ? \ShopUrl::getMainShopDomainSSL() : \ShopUrl::getMainShopDomain();
        $domain = ShopUrlHelper::validateAuthority($domain);

        if ($domain === null) {
            throw new \UnexpectedValueException('Unable to build callback URL: shop domain is not configured or valid.');
        }

        return \Tools::htmlentitiesutf8($scheme . $domain . __PS_BASE_URI__);
    }

    /**
     * Resolve the shop's configured HTTPS domain directly from ShopUrl, never from the
     * request's Host header. Used for the seller_website metadata field, which is not tied
     * to any ticket in this batch — closed alongside getSiteUrl()/getNotificationUrl()
     * (PPSP-1603, CWE-601). Returns '' instead of failing closed since this is informational
     * metadata sent to Mercado Pago, not a redirect or callback target.
     *
     * @return string
     */
    protected function getConfiguredSslDomainUrl()
    {
        $domain = ShopUrlHelper::getConfiguredSslAuthority();

        return $domain !== null ? 'https://' . \Tools::htmlentitiesutf8($domain) : '';
    }

    /**
     * Get sponsor_id for order
     *
     * @return string
     */
    public function getSponsorId()
    {
        $sponsor_id = $this->getCountryConfigs(\Configuration::get('MERCADOPAGO_COUNTRY_LINK'))['sponsor_id'];

        return $sponsor_id;
    }

    /**
     * Get currency_id for order
     *
     * @return string
     */
    public function getCurrencyId()
    {
        return $this->context->currency->iso_code;
    }

    /**
     * Get country configs for order
     *
     * @return array
     */
    public static function getAllCountryConfigs(): array
    {
        return [
            'MCO' => [
                'site_id' => 'MCO',
                'sponsor_id' => '237788769',
                'currency' => 'COP',
            ],
            'MLA' => [
                'site_id' => 'MLA',
                'sponsor_id' => '237788409',
                'currency' => 'ARS',
            ],
            'MLB' => [
                'site_id' => 'MLB',
                'sponsor_id' => '236914421',
                'currency' => 'BRL',
            ],
            'MLC' => [
                'site_id' => 'MLC',
                'sponsor_id' => '237788173',
                'currency' => 'CLP',
            ],
            'MLM' => [
                'site_id' => 'MLM',
                'sponsor_id' => '237793014',
                'currency' => 'MXN',
            ],
            'MLU' => [
                'site_id' => 'MLU',
                'sponsor_id' => '241729464',
                'currency' => 'UYU',
            ],
            'MLV' => [
                'site_id' => 'MLV',
                'sponsor_id' => '237789083',
                'currency' => 'VEF',
            ],
            'MPE' => [
                'site_id' => 'MPE',
                'sponsor_id' => '237791025',
                'currency' => 'PEN',
            ],
        ];
    }

    public function getCountryConfigs($country)
    {
        return self::getAllCountryConfigs()[strtoupper($country)];
    }

    /**
     * Get items description
     *
     * @return array|string
     */
    public function getPreferenceDescription($cart)
    {
        $items = [];
        $products = $cart->getProducts();

        foreach ($products as $product) {
            $items[] = $product['name'] . ' x ' . $product['quantity'];
        }

        $items = implode(', ', $items);

        return $items;
    }

    /**
     * Get customer email
     *
     * @return array
     *
     * @throws \PrestaShopException
     */
    public function getCustomerEmail()
    {
        $customer_fields = $this->context->customer->getFields();
        $customer_email = $customer_fields['email'];

        return $customer_email;
    }

    /**
     * Get customer data for custom checkout
     *
     * @return array|null
     *
     * @throws \PrestaShopException
     */
    public function getCustomCustomerData($cart)
    {
        $customer = $this->context->customer;
        if (empty($customer->firstname) && empty($customer->lastname)) {
            return null;
        }

        $is_logged = $this->context->customer->isLogged();
        $customer_fields = $customer->getFields();
        $address_invoice = new \Address((int) $cart->id_address_invoice);

        return [
            'first_name' => $customer_fields['firstname'],
            'last_name' => $customer_fields['lastname'],
            'phone' => [
                'area_code' => '-',
                'number' => $address_invoice->phone,
            ],
            'address' => [
                'zip_code' => $address_invoice->postcode,
                'street_name' => $this->buildStreetName($address_invoice),
                'street_number' => '-',
            ],
            'registration_date' => $is_logged ? $customer_fields['date_add'] : ' ',
            'registered_user' => $is_logged ? 'yes' : 'no',
        ];
    }

    /**
     * build street name
     *
     * @param object $address_data
     *
     * @return string
     */
    public function buildStreetName($address_data)
    {
        $address = $address_data->address1 . ' - ' .
        $address_data->address2 . ' - ' .
        $address_data->city . ' - ' .
        $address_data->country;

        return $address;
    }

    /**
     * Get shippment address
     *
     * @return array
     */
    public function getShipmentAddress($cart)
    {
        $address_shipment = new \Address((int) $cart->id_address_delivery);

        $shipment = [
            'receiver_address' => [
                'zip_code' => $address_shipment->postcode,
                'street_name' => $this->buildStreetName($address_shipment),
                'street_number' => '-',
                'apartment' => '-',
                'floor' => '-',
                'city_name' => $address_shipment->city,
            ],
        ];

        return $shipment;
    }

    /**
     * Get all cart items
     *
     * @param $cart
     * @param float|null $percent
     *
     * @return array
     *
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    public function getCartItems($cart, $percent = null)
    {
        $items = $this->getProductItems($cart, $percent);
        $round = $this->getRound();

        $this->addWrappingItem($items, $cart, $round);
        $this->addDiscountItem($items, $cart, $round);
        $this->addShippingItem($items, $cart, $round);
        $this->addPriceDifferenceItem($items, $cart, $round);

        return $items;
    }

    private function getProductItems($cart, $percent)
    {
        $items = [];
        $products = $cart->getProducts();
        $round = $this->getRound();
        $categoryId = (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY');

        foreach ($products as $product) {
            $image = \Image::getCover($product['id_product']);
            $image_product = new \Product($product['id_product'], false, $this->context->language->id);
            $link = new \Link();
            $link_image = $link->getImageLink($image_product->link_rewrite, $image['id_image'], '');

            $product_price = $product['price_wt'];
            if ($percent !== null) {
                $product_price = (float) $product_price - ($product_price * ($percent / 100));
            }

            $items[] = [
                'id' => (string) $product['id_product'],
                'title' => $product['name'],
                'quantity' => $product['quantity'],
                'unit_price' => $round ? (string) \Tools::ps_round($product_price) : (string) $product_price,
                'picture_url' => $link_image,
                'category_id' => $categoryId,
                'description' => strip_tags($product['description_short']),
            ];
        }

        return $items;
    }

    private function addWrappingItem(&$items, $cart, $round)
    {
        $wrapping_cost = (float) $cart->getOrderTotal(true, \Cart::ONLY_WRAPPING);
        if ($wrapping_cost <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Wrapping',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($wrapping_cost) : (string) $wrapping_cost,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Wrapping service used by store',
        ];
    }

    private function addDiscountItem(&$items, $cart, $round)
    {
        $discounts = (float) $cart->getOrderTotal(true, \Cart::ONLY_DISCOUNTS);
        if ($discounts <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Discount',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round(-$discounts) : (string) -$discounts,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Discount provided by store',
        ];
    }

    private function addShippingItem(&$items, $cart, $round)
    {
        $shipping_cost = (float) $cart->getOrderTotal(true, \Cart::ONLY_SHIPPING);
        if ($shipping_cost <= 0) {
            return;
        }

        $items[] = [
            'title' => 'Shipping',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($shipping_cost) : (string) $shipping_cost,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Shipping service used by store',
        ];
    }

    private function addPriceDifferenceItem(&$items, $cart, $round)
    {
        $cartTotal = $round ? \Tools::ps_round($cart->getOrderTotal(true)) : $cart->getOrderTotal();
        $itemsTotal = array_reduce(
            $items,
            function ($accumulator, $item) {
                return $accumulator + ($item['unit_price'] * $item['quantity']);
            },
            0
        );

        $itemsTotal = $round ? \Tools::ps_round($itemsTotal) : \Tools::ps_round($itemsTotal, 2);
        $priceDiff = $round
            ? \Tools::ps_round($cartTotal - $itemsTotal)
            : \Tools::ps_round($cartTotal - $itemsTotal, 2);

        if ($priceDiff == 0) {
            return;
        }

        $items[] = [
            'title' => 'Difference',
            'quantity' => 1,
            'unit_price' => $round ? (string) \Tools::ps_round($priceDiff) : (string) $priceDiff,
            'category_id' => (string) \Configuration::get('MERCADOPAGO_STORE_CATEGORY'),
            'description' => 'Adjustment for the Mercado Pago price to be the same as the store',
        ];
    }

    /**
     * Get round
     */
    public function getRound(): bool
    {
        return TransactionAmountNormalizer::usesZeroDecimalPrecision(
            \Configuration::get('MERCADOPAGO_COUNTRY_LINK')
        );
    }

    /**
     * Normalize an order amount according to the configured country's currency precision.
     */
    protected function normalizeTransactionAmount(float $amount): float
    {
        return TransactionAmountNormalizer::normalize(
            $amount,
            \Configuration::get('MERCADOPAGO_COUNTRY_LINK')
        );
    }

    /**
     * Create and set ticket discount on CartRule()
     *
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     * @throws CartRuleApplicationException
     */
    public function setCartRule(\Cart $cart, $discount): void
    {
        $cartId = (int) $cart->id;
        if (!$this->transactionRepository->acquireNotificationLock($cartId)) {
            throw new CartRuleApplicationException('Unable to acquire the Mercado Pago cart rule lock');
        }

        try {
            $this->synchronizeCartRuleWhileLocked($cart, (float) $discount);
        } finally {
            $this->transactionRepository->releaseNotificationLock($cartId);
        }
    }

    /**
     * Synchronize the module-owned rule and calculate the matching financial
     * payload in one cart-scoped critical section. The caller must perform the
     * Mercado Pago HTTP request only after this method returns.
     *
     * @return mixed
     */
    protected function withSynchronizedCartRule(
        \Cart $cart,
        $discount,
        callable $operation,
    ) {
        $this->clearCapturedFinancialSnapshot();
        $cartId = (int) $cart->id;
        $discountPercent = (float) $discount;
        if (!$this->transactionRepository->acquireNotificationLock($cartId)) {
            throw new CartRuleApplicationException('Unable to acquire the Mercado Pago cart rule lock');
        }

        $failure = null;
        $cleanupFailure = null;
        $payload = null;
        try {
            $this->synchronizeCartRuleWhileLocked($cart, $discountPercent);
            $initialToken = $this->cartRuleRepository->getFinancialStateToken($cart);
            $payload = $operation();
            $finalToken = $this->cartRuleRepository->getFinancialStateToken($cart);

            if (!hash_equals($initialToken, $finalToken)) {
                throw new FinancialCartMismatchException('The cart changed while building the Mercado Pago financial payload');
            }

            $this->captureFinancialSnapshot($cart, $discountPercent, $payload, $finalToken);
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            // The remote request runs without the named lock. Never expose a
            // request-specific rule while waiting for I/O, including when
            // payload construction or snapshot validation fails.
            if ($discountPercent > 0) {
                try {
                    $this->neutralizeCartRulesWhileLocked($cart);
                } catch (\Throwable $cleanupException) {
                    $this->clearCapturedFinancialSnapshot();
                    $cleanupFailure = $cleanupException;
                }
            }
            $this->transactionRepository->releaseNotificationLock($cartId);
        }

        if ($failure !== null) {
            if ($cleanupFailure !== null) {
                \PrestaShopLogger::addLog(
                    'Unable to neutralize the Mercado Pago cart rule after payload failure',
                    3
                );
            }
            throw $failure;
        }
        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        return $payload;
    }

    private function synchronizeCartRuleWhileLocked(\Cart $cart, float $discountPercent): void
    {
        if ($discountPercent > 0) {
            $this->applyCartRuleWhileLocked($cart, $discountPercent);

            return;
        }

        $this->neutralizeCartRulesWhileLocked($cart);
    }

    private function captureFinancialSnapshot(
        \Cart $cart,
        float $discountPercent,
        $payload,
        string $stateToken,
    ): void {
        if (!is_array($payload)
            || !array_key_exists('items', $payload)
            || !array_key_exists('transaction_amount', $payload)
            || !is_array($payload['items'])
        ) {
            throw new \UnexpectedValueException('The Mercado Pago financial payload is incomplete');
        }

        $transactionAmount = $this->validateFinancialAmount($payload['transaction_amount']);
        $this->assertFinancialItemsMatchAmount($payload['items'], $transactionAmount);
        $paymentAmount = $payload['payments'][0]['amount'] ?? null;
        if ($paymentAmount !== null) {
            $this->assertFinancialAmountsMatch(
                $transactionAmount,
                $this->validateFinancialAmount($paymentAmount)
            );
        }

        $this->capturedFinancialCartId = (int) $cart->id;
        $this->capturedDiscountPercent = max(0.0, $discountPercent);
        $this->capturedTransactionAmount = $transactionAmount;
        $this->capturedItemsHash = $this->hashFinancialItems($payload['items']);
        $this->capturedFinancialStateToken = $stateToken;
    }

    /**
     * Prove the exact outbound item list against the amount while the cart lock
     * is still held. Cart totals and catalog-derived item prices are separate
     * reads in PrestaShop; this prevents a concurrent price change from being
     * hidden by the synthetic Difference item before the HTTP request.
     */
    private function assertFinancialItemsMatchAmount(array $items, float $transactionAmount): void
    {
        $itemsTotal = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)
                || !array_key_exists('unit_price', $item)
                || !array_key_exists('quantity', $item)
                || (!is_int($item['unit_price'])
                    && !is_float($item['unit_price'])
                    && !is_string($item['unit_price']))
                || (!is_int($item['quantity'])
                    && !is_float($item['quantity'])
                    && !is_string($item['quantity']))
                || !is_numeric($item['unit_price'])
                || !is_numeric($item['quantity'])
            ) {
                throw new \UnexpectedValueException('The Mercado Pago financial item payload is invalid');
            }

            $unitPrice = (float) $item['unit_price'];
            $quantity = (float) $item['quantity'];
            if (!is_finite($unitPrice)
                || !is_finite($quantity)
                || $quantity <= 0
                || floor($quantity) !== $quantity
            ) {
                throw new \UnexpectedValueException('The Mercado Pago financial item payload is invalid');
            }

            $itemsTotal += $unitPrice * $quantity;
            if (!is_finite($itemsTotal)) {
                throw new \UnexpectedValueException('The Mercado Pago financial item total is invalid');
            }
        }

        $this->assertFinancialAmountsMatch(
            $transactionAmount,
            $this->normalizeTransactionAmount($itemsTotal)
        );
    }

    private function clearCapturedFinancialSnapshot(): void
    {
        $this->capturedFinancialCartId = null;
        $this->capturedDiscountPercent = null;
        $this->capturedTransactionAmount = null;
        $this->capturedItemsHash = null;
        $this->capturedFinancialStateToken = null;
    }

    /**
     * Return the exact amount sent to Mercado Pago for transaction persistence.
     */
    public function getCapturedTransactionAmount(\Cart $cart): float
    {
        $this->assertCapturedFinancialSnapshotBelongsTo($cart);

        return (float) $this->capturedTransactionAmount;
    }

    /**
     * Reapply the captured attempt under the order-creation lock and prove that
     * the cart, items and API response still match the request sent remotely.
     * The caller must already hold the cart-scoped notification lock.
     */
    public function prepareCapturedCartRuleForOrder(\Cart $cart, array $orderResponse): void
    {
        $this->assertCapturedFinancialSnapshotBelongsTo($cart);
        $capturedAmount = (float) $this->capturedTransactionAmount;

        $this->assertCapturedOrderResponseMatches($cart, $orderResponse);

        $this->synchronizeCartRuleWhileLocked($cart, (float) $this->capturedDiscountPercent);
        $currentToken = $this->cartRuleRepository->getFinancialStateToken($cart);
        if (!hash_equals((string) $this->capturedFinancialStateToken, $currentToken)) {
            throw new FinancialCartMismatchException('The cart changed after the Mercado Pago financial payload was created');
        }

        $currentAmount = $this->normalizeTransactionAmount((float) $cart->getOrderTotal());
        $this->assertFinancialAmountsMatch($capturedAmount, $currentAmount);
        if (!hash_equals(
            (string) $this->capturedItemsHash,
            $this->hashFinancialItems($this->getCartItems($cart))
        )) {
            throw new FinancialCartMismatchException('The cart items changed after the Mercado Pago financial payload was created');
        }
    }

    /**
     * Require the successful Order API response to echo at least one canonical
     * amount and prove every amount it carries against the captured request.
     * Replayed metadata preserves the original request amount, and this check
     * runs before ownership reservation, so an older idempotent response cannot
     * authorize a changed cart or local order.
     */
    public function assertCapturedOrderResponseMatches(\Cart $cart, array $orderResponse): void
    {
        $this->assertCapturedFinancialSnapshotBelongsTo($cart);
        $capturedAmount = (float) $this->capturedTransactionAmount;
        $hasCanonicalAmount = false;

        try {
            if (array_key_exists('total_amount', $orderResponse)) {
                $this->assertFinancialAmountsMatch(
                    $capturedAmount,
                    $this->validateFinancialAmount($orderResponse['total_amount'])
                );
                $hasCanonicalAmount = true;
            }

            if (array_key_exists('payments', $orderResponse)) {
                if (!is_array($orderResponse['payments'])
                    || !isset($orderResponse['payments'][0])
                    || !is_array($orderResponse['payments'][0])
                    || !array_key_exists('amount', $orderResponse['payments'][0])
                ) {
                    throw new \UnexpectedValueException('The Mercado Pago response payment amount is invalid');
                }
                $this->assertFinancialAmountsMatch(
                    $capturedAmount,
                    $this->validateFinancialAmount($orderResponse['payments'][0]['amount'])
                );
                $hasCanonicalAmount = true;
            }

            if (!$hasCanonicalAmount) {
                throw new \UnexpectedValueException('The Mercado Pago response does not contain a financial amount');
            }
        } catch (\UnexpectedValueException $exception) {
            throw new FinancialCartMismatchException('The Mercado Pago response contains an invalid financial amount', 0, $exception);
        }
    }

    /**
     * Keep native cart writes blocked through validateOrder.
     *
     * The callback receives a freshly loaded Cart bound to the repeatable-read
     * transaction. Writes on other connections wait for the locked rows;
     * PrestaShop hooks running on this same connection remain inside the
     * transaction and are covered by the post-order amount verification.
     *
     * @return mixed
     */
    public function withFinancialStateTransaction(\Cart $cart, callable $operation)
    {
        $cartId = (int) $cart->id;
        $this->transactionRepository->beginCartOrderTransactionAndLock($cartId);

        try {
            $lockedCart = new \Cart($cartId);
            if ((int) $lockedCart->id !== $cartId) {
                throw new \RuntimeException('Could not reload the locked checkout cart');
            }

            $result = $operation($lockedCart);
            $this->transactionRepository->commitCartOrderTransaction();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $this->transactionRepository->rollbackCartOrderTransaction();
            } catch (\Throwable) {
                \PrestaShopLogger::addLog(
                    'Unable to roll back the Mercado Pago financial cart transaction',
                    3
                );
            }
            $this->cart_rule = null;
            $this->clearCapturedFinancialSnapshot();
            $this->clearCartRuleCalculationCaches($cartId);

            throw $exception;
        }
    }

    /**
     * Remove every module-owned rule after validateOrder while the caller still
     * holds the order-creation lock.
     */
    public function finalizeCapturedCartRuleForOrder(\Cart $cart): void
    {
        try {
            $this->neutralizeCartRulesWhileLocked($cart);
        } finally {
            $this->clearCapturedFinancialSnapshot();
        }
    }

    /**
     * Verify the amount materialized by validateOrder before the SQL snapshot
     * is committed. Multiple orders sharing one reference are summed because
     * PrestaShop may split a cart by package or delivery address.
     */
    public function assertCapturedOrderMatches(\Cart $cart, \Order $order): void
    {
        $this->assertCapturedFinancialSnapshotBelongsTo($cart);
        $orders = $this->transactionRepository->findOrderTotalsByReferenceAndCart(
            (string) $order->reference,
            (int) $cart->id
        );

        $materializedAmount = 0.0;
        $orderCount = 0;
        foreach ($orders as $materializedOrder) {
            if (!(int) ($materializedOrder['id_order'] ?? 0)
                || !isset($materializedOrder['total_paid_tax_incl'])
                || !is_numeric($materializedOrder['total_paid_tax_incl'])
            ) {
                throw new FinancialCartMismatchException('The PrestaShop order financial state is incomplete');
            }
            $materializedAmount += (float) $materializedOrder['total_paid_tax_incl'];
            ++$orderCount;
        }

        if ($orderCount === 0) {
            throw new FinancialCartMismatchException('The PrestaShop order financial state is incomplete');
        }
        $this->assertFinancialAmountsMatch(
            (float) $this->capturedTransactionAmount,
            $materializedAmount
        );
    }

    private function assertCapturedFinancialSnapshotBelongsTo(\Cart $cart): void
    {
        if ($this->capturedFinancialCartId !== (int) $cart->id
            || $this->capturedDiscountPercent === null
            || $this->capturedTransactionAmount === null
            || $this->capturedItemsHash === null
            || $this->capturedFinancialStateToken === null
        ) {
            throw new \LogicException('The Mercado Pago financial snapshot is unavailable for this cart');
        }
    }

    private function validateFinancialAmount($amount): float
    {
        if (!is_numeric($amount)) {
            throw new \UnexpectedValueException('The Mercado Pago financial amount is invalid');
        }

        $value = (float) $amount;
        if (!is_finite($value) || $value < 0) {
            throw new \UnexpectedValueException('The Mercado Pago financial amount is invalid');
        }

        return $value;
    }

    private function assertFinancialAmountsMatch(float $expected, float $actual): void
    {
        $precision = $this->getRound() ? 0 : 2;
        if (abs(
            \Tools::ps_round($expected, $precision) - \Tools::ps_round($actual, $precision)
        ) > 0.000001) {
            throw new FinancialCartMismatchException('The Mercado Pago financial amount no longer matches the cart');
        }
    }

    private function hashFinancialItems(array $items): string
    {
        return hash(
            'sha256',
            json_encode($items, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    private function neutralizeCartRulesWhileLocked(\Cart $cart): void
    {
        $cartId = (int) $cart->id;
        $ruleIds = $this->cartRuleRepository->findIdsForCart($cartId);
        $this->neutralizeRuleIdsWhileLocked($cart, $ruleIds);

        $this->cart_rule = null;
    }

    /**
     * Detach and disable every supplied rule, continuing after individual
     * failures so one bad legacy row cannot leave later duplicates active.
     *
     * @param int[] $ruleIds
     */
    private function neutralizeRuleIdsWhileLocked(\Cart $cart, array $ruleIds): void
    {
        $cleanupFailed = false;

        foreach ($ruleIds as $ruleId) {
            $removed = false;
            $disabled = false;

            try {
                $removed = $cart->removeCartRule((int) $ruleId) === true;
            } catch (\Throwable) {
                // Still disable this row and continue with every later duplicate.
            }

            try {
                $cartRule = new \CartRule((int) $ruleId);
                $cartRule->active = false;
                $disabled = $cartRule->save() === true;
            } catch (\Throwable) {
                // Report one bounded domain error after all attempts finish.
            }

            if (!$removed || !$disabled) {
                $cleanupFailed = true;
            }
        }

        $this->clearCartRuleCalculationCaches((int) $cart->id);
        if ($cleanupFailed) {
            throw new CartRuleApplicationException('Unable to neutralize the Mercado Pago cart rules');
        }
    }

    private function applyCartRuleWhileLocked(\Cart $cart, float $discount): void
    {
        $cartId = (int) $cart->id;
        $customerId = (int) $cart->id_customer;
        if ($customerId <= 0) {
            throw new CartRuleApplicationException('A persisted customer is required for the Mercado Pago cart rule');
        }
        $store_name = \Configuration::get('PS_LANG_DEFAULT');
        $discount_name = 'Mercado Pago discount applied to cart ' . $cartId;

        $ruleIds = $this->cartRuleRepository->findIdsForCart($cartId);
        $existingId = $ruleIds[0] ?? 0;

        $cart_rule = new \CartRule($existingId ?: null);
        $cart_rule->date_from = date('Y-m-d H:i:s', time() - 60);
        $cart_rule->date_to = date('Y-m-d H:i:s', time() + 1800);
        $cart_rule->name[$store_name] = $discount_name;
        PaymentMethodDiscountRule::configure(
            $cart_rule,
            $cartId,
            $discount,
            $customerId
        );

        $duplicateIds = array_slice($ruleIds, 1);
        if ($duplicateIds !== []) {
            $this->neutralizeRuleIdsWhileLocked($cart, $duplicateIds);
        }

        if (!$cart_rule->save()) {
            throw new CartRuleApplicationException('Unable to save the Mercado Pago cart rule');
        }

        $this->clearCartRuleCalculationCaches($cartId);
        $orderedCartRules = $cart->getOrderedCartRulesIds(\CartRule::FILTER_ACTION_ALL);
        if (!is_array($orderedCartRules)) {
            throw new CartRuleApplicationException('Unable to inspect the cart rule association');
        }

        $cartRuleIds = array_map(
            'intval',
            array_column($orderedCartRules, 'id_cart_rule')
        );
        $associationExists = in_array((int) $cart_rule->id, $cartRuleIds, true);

        if (!$associationExists && $cart->addCartRule($cart_rule->id) !== true) {
            throw new CartRuleApplicationException('Unable to associate the Mercado Pago cart rule');
        }

        $this->clearCartRuleCalculationCaches($cartId);
        $this->cart_rule = $cart_rule->id;
    }

    private function clearCartRuleCalculationCaches(int $cartId): void
    {
        \Cart::resetStaticCache();
        \Cache::clean('Cart::getCartRules_' . $cartId . '-*');
        \Cache::clean('Cart::getOrderedCartRulesIds_' . $cartId . '-*');
        \Cache::clean('getContextualValue_*');
        \Cache::clean('getPackageShippingCost_' . $cartId . '_*');
    }

    /**
     * Disable cart rule when buyer completes purchase
     *
     * @throws \PrestaShopDatabaseException
     * @throws \PrestaShopException
     */
    public function disableCartRule(): void
    {
        if (!$this->isDiscountEnabled()) {
            return;
        }

        $cart_rule = new \CartRule($this->cart_rule);
        $cart_rule->active = false;
        $cart_rule->save();
    }

    /**
     * Delete cart rule from database when an error occurs during payment.
     * Falls back to disableCartRule if deletion fails.
     *
     * @return bool
     */
    public function deleteCartRule(): bool
    {
        if (!$this->isDiscountEnabled()) {
            return false;
        }

        if (!$this->cart_rule) {
            return false;
        }

        $resultRule = \Db::getInstance()->delete('cart_rule', 'id_cart_rule = ' . (int) $this->cart_rule);
        $resultLang = \Db::getInstance()->delete('cart_rule_lang', 'id_cart_rule = ' . (int) $this->cart_rule);
        $resultCart = \Db::getInstance()->delete('cart_cart_rule', 'id_cart_rule = ' . (int) $this->cart_rule);

        if (!$resultRule || !$resultLang || !$resultCart) {
            \PrestaShopLogger::addLog('Failed to delete cart_rule ' . $this->cart_rule . ' from database', 3);
            $this->disableCartRule();

            return false;
        }

        return true;
    }

    /**
     * Disable cart rule and remove it from the cart (used on payment failure)
     */
    public function removeCartRuleFromCart(\Cart $cart): void
    {
        $cartId = (int) $cart->id;
        if (!$this->transactionRepository->acquireNotificationLock($cartId)) {
            \PrestaShopLogger::addLog(
                'Unable to acquire the Mercado Pago cart rule cleanup lock',
                3
            );

            return;
        }

        try {
            $this->neutralizeCartRulesWhileLocked($cart);
        } catch (\Throwable) {
            \PrestaShopLogger::addLog(
                'Unable to neutralize every Mercado Pago cart rule during cleanup',
                3
            );
        } finally {
            $this->clearCapturedFinancialSnapshot();
            $this->transactionRepository->releaseNotificationLock($cartId);
        }
    }

    public function clearCartAfterOrder(\Context $context)
    {
        $currentCart = $context->cart;
        if (!$currentCart || !$currentCart->id) {
            return;
        }
        $newCart = new \Cart();
        $newCart->id_customer = (int) $currentCart->id_customer;
        $newCart->id_shop_group = (int) $currentCart->id_shop_group;
        $newCart->id_shop = (int) $currentCart->id_shop;
        $newCart->id_lang = (int) $currentCart->id_lang;
        $newCart->id_currency = (int) $currentCart->id_currency;
        $newCart->id_address_delivery = (int) $currentCart->id_address_delivery;
        $newCart->id_address_invoice = (int) $currentCart->id_address_invoice;
        $newCart->save();
        $context->cart = new \Cart($newCart->id);
        $context->cookie->__set('id_cart', (int) $newCart->id);
        $context->cookie->write();
    }

    /**
     * Get seller data
     *
     * @return array
     */
    public function getSellerData()
    {
        $seller = $this->isValidAccessToken();
        $seller_data = [];
        if (is_array($seller)) {
            $seller_data = [
                'id' => (string) $seller['id'],
                'email' => (string) $seller['email'],
                'first_name' => (string) $seller['first_name'],
                'last_name' => (string) $seller['last_name'],
                'identification' => [
                    'type' => (string) $seller['identification']['type'],
                    'number' => (string) $seller['identification']['number'],
                ],
                'address' => [
                    'zip_code' => (string) $seller['address']['zip_code'],
                    'street_name' => (string) $seller['address']['address'],
                    'street_number' => '-',
                    'city' => (string) $seller['address']['city'],
                    'state' => (string) $seller['address']['state'],
                    'country' => (string) $seller['country_id'],
                    'neighborhood' => '-',
                    'complement' => '-',
                    'floor' => '-',
                ],
                'phone' => [
                    'area_code' => (string) $seller['phone']['area_code'],
                    'number' => (string) $seller['phone']['number'],
                ],
                'referral_url' => (string) $seller['permalink'],
                'registration_date' => (string) $seller['registration_date'],
            ];
        }
        $seller_data['website'] = $this->getConfiguredSslDomainUrl();

        return $seller_data;
    }

    /**
     * Is valid access token
     *
     * @return array|false
     */
    public function isValidAccessToken()
    {
        $response = $this->mpApi->getUserInfo();
        if (!is_array($response)
            || !isset($response['status'])
            || $response['status'] < 200
            || $response['status'] > 202
            || !is_array($response['response'] ?? null)
        ) {
            \PrestaShopLogger::addLog('API valid_access_token error', 1, null, null, 0, true);

            return false;
        }
        $sellerId = (int) ($response['response']['id'] ?? 0);
        if ($sellerId > 0) {
            $this->capturedCollectorId = $sellerId;
        }

        return $response['response'];
    }

    /**
     * Is test user
     *
     * @return bool
     */
    public function isTestUser()
    {
        $response = $this->mpApi->getUserInfo();
        if (!is_array($response)
            || !isset($response['status'])
            || $response['status'] < 200
            || $response['status'] > 202
            || !is_array($response['response'] ?? null)
        ) {
            \PrestaShopLogger::addLog('API is_test_user error', 1, null, null, 0, true);

            return false;
        }
        $sellerId = (int) ($response['response']['id'] ?? 0);
        if ($sellerId > 0) {
            $this->capturedCollectorId = $sellerId;
        }
        $tags = $response['response']['tags'] ?? [];
        $isTestUser = is_array($tags) && in_array('test_user', $tags, true);

        return $isTestUser;
    }

    /**
     * Get location data from shipping address
     *
     * @param \Cart $cart
     *
     * @return array|null
     */
    public function getLocationData($cart)
    {
        $address = new \Address((int) $cart->id_address_delivery);

        if (!$address->id) {
            return null;
        }

        $countryIso = \Country::getIsoById((int) $address->id_country);
        $stateId = $countryIso;

        if ((int) $address->id_state > 0) {
            $state = new \State((int) $address->id_state);
            $stateId = $countryIso . '-' . $state->iso_code;
        }

        return [
            'state_id' => $stateId,
            'source' => 'shipment',
        ];
    }

    /**
     * Redirect if any errors occurs
     */
    public function redirectError(): void
    {
        \Tools::redirect('index.php?controller=order&step=3&typeReturn=failure');
    }

    public function saveCreateOrderData($cart, $orderResponseOrNotificationUrl = null, $maybeOrderResponse = null): int
    {
        try {
            list($orderResponse, $notificationUrl) = $this->parseOrderResponse($orderResponseOrNotificationUrl, $maybeOrderResponse);

            $paymentData = $this->extractPaymentData($orderResponse);
            $idCart = (int) $cart->id;
            $idCustomer = (int) $cart->id_customer;
            $amountSource = array_key_exists('total_amount', $orderResponse)
                ? $orderResponse['total_amount']
                : $cart->getOrderTotal();
            $amount = (string) \Tools::ps_round(
                $this->validateFinancialAmount($amountSource),
                2
            );
            $notificationUrl = $notificationUrl ?: $orderResponse['notification_url'] ?? $this->getNotificationUrl($cart);
            $isTest = !((bool) \Configuration::get('MERCADOPAGO_PROD_STATUS'));

            $existingId = $this->getExistingTransactionId($orderResponse);
            $module = \Module::getInstanceByName('mercadopago');
            $version = ($module && !empty($module->version)) ? (string) $module->version : '1.0.0';
            $mpModuleId = $this->transactionRepository->getOrCreateModuleId($version);
            $apiOrderId = isset($orderResponse['id']) ? (string) $orderResponse['id'] : null;
            $customerIdForTransaction = $this->getCollectorIdFromResponse($orderResponse);
            if ($customerIdForTransaction === null) {
                $customerIdForTransaction = $this->getCollectorIdFromSeller();
            }

            $data = $this->buildTransactionData(
                $idCart,
                (int) $customerIdForTransaction,
                $apiOrderId,
                $amount,
                $mpModuleId,
                $notificationUrl,
                $isTest,
                $paymentData
            );

            return $this->saveTransactionData($data, $existingId);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function updateTransactionOrderId($psOrderId, $idCart)
    {
        if ((int) $psOrderId <= 0 || (int) $idCart <= 0) {
            return false;
        }

        return $this->transactionRepository->updateMerchantOrderIdByCartId((int) $idCart, (int) $psOrderId);
    }

    /**
     * Reserve order creation for the exact persisted checkout attempt.
     */
    public function reserveTransactionOrderCreationForAttempt(int $cartId, int $transactionId): bool
    {
        if ($cartId <= 0 || $transactionId <= 0) {
            return false;
        }

        $existingOrderId = (int) \Order::getIdByCartId($cartId);
        $transaction = $this->transactionRepository->findByIdAndCart($transactionId, $cartId);
        if ($transaction === null) {
            throw new \RuntimeException('Could not reload checkout transaction');
        }

        $paymentStatus = (string) ($transaction['payment_status'] ?? '');
        if ($existingOrderId <= 0
            && in_array($paymentStatus, self::TERMINAL_NEGATIVE_PAYMENT_STATUSES, true)
        ) {
            return false;
        }

        if ($existingOrderId > 0) {
            $alreadyLinked = $this->transactionRepository->isTransactionLinkedToOrder(
                $transactionId,
                $cartId,
                $existingOrderId
            );
            if ($alreadyLinked === null) {
                throw new \RuntimeException('Could not verify checkout order ownership');
            }
            if ($alreadyLinked) {
                return true;
            }
        }

        $reserved = $this->transactionRepository->reserveOrderCreation($transactionId, $cartId);
        if ($reserved === null) {
            throw new \RuntimeException('Could not persist checkout order ownership reservation');
        }

        return $reserved;
    }

    /**
     * Mark a persisted remote attempt as unsafe for automatic order recovery.
     */
    public function invalidateTransactionOrderCreationForAttempt(
        int $cartId,
        int $transactionId,
    ): bool {
        $invalidated = $this->transactionRepository->markOrderCreationInvalid(
            $transactionId,
            $cartId
        );
        if ($invalidated === null) {
            throw new \RuntimeException('Could not persist invalid checkout attempt state');
        }

        return $invalidated;
    }

    /**
     * Stop automated recovery when validateOrder may have emitted external
     * side effects that the local SQL rollback could not undo.
     */
    public function markTransactionOrderCreationUncertainForAttempt(
        int $cartId,
        int $transactionId,
    ): bool {
        $marked = $this->transactionRepository->markOrderCreationUncertain(
            $transactionId,
            $cartId
        );
        if ($marked === null) {
            throw new \RuntimeException('Could not persist uncertain checkout attempt state');
        }

        return $marked;
    }

    /**
     * Return a pre-order failure to the retryable exact-attempt reservation.
     */
    public function restoreTransactionOrderCreationReservationForAttempt(
        int $cartId,
        int $transactionId,
    ): bool {
        $restored = $this->transactionRepository->restoreOrderCreationReservation(
            $transactionId,
            $cartId
        );
        if ($restored === null) {
            throw new \RuntimeException('Could not restore checkout order ownership reservation');
        }

        return $restored;
    }

    /**
     * Link only the exact checkout attempt that created or reused the order.
     */
    public function updateTransactionOrderIdForAttempt(
        int $psOrderId,
        int $cartId,
        int $transactionId,
    ): bool {
        if ($psOrderId <= 0 || $cartId <= 0 || $transactionId <= 0) {
            return false;
        }

        $alreadyLinked = $this->transactionRepository->isTransactionLinkedToOrder(
            $transactionId,
            $cartId,
            $psOrderId
        );
        if ($alreadyLinked === null) {
            throw new \RuntimeException('Could not verify deferred checkout order ownership');
        }
        if ($alreadyLinked) {
            return true;
        }

        $order = new \Order($psOrderId);
        if (!OrderAttemptReference::matches($order, $transactionId, $cartId)) {
            return false;
        }

        $linked = $this->transactionRepository->linkMerchantOrderId(
            $transactionId,
            $cartId,
            $psOrderId
        );
        if ($linked === null) {
            throw new \RuntimeException('Could not persist deferred checkout order ownership');
        }

        return $linked;
    }

    /**
     * Resolve the exact preference attempt named by Mercado Pago's browser return.
     *
     * @throws \DomainException when the attempt is invalid, absent or ambiguous
     * @throws \RuntimeException when its persistence state cannot be read
     */
    public function resolveDeferredCheckoutAttempt(int $cartId, string $preferenceId): array
    {
        $transaction = $this->transactionRepository->findUniqueDeferredAttempt($cartId, $preferenceId);
        if ($transaction === null
            || (int) ($transaction['id_mp_transaction'] ?? 0) <= 0
            || (int) ($transaction['cart_id'] ?? 0) !== $cartId
        ) {
            throw new \DomainException('Deferred checkout attempt could not be resolved');
        }

        return $transaction;
    }

    /**
     * Serialize transaction persistence and PrestaShop order creation with webhook processing.
     *
     * @return mixed
     */
    public function withOrderCreationLock(int $cartId, callable $operation)
    {
        if (!$this->transactionRepository->acquireNotificationLock($cartId)) {
            throw new \RuntimeException('Could not acquire order creation lock');
        }

        try {
            return $operation();
        } finally {
            $this->transactionRepository->releaseNotificationLock($cartId);
        }
    }

    /**
     * Serialize a deferred Checkout Pro return with webhook processing.
     *
     * Preference and Wallet Button persist mp_transactions before redirecting the buyer and
     * create the PrestaShop order only when the buyer returns. A terminal negative webhook can
     * therefore be delivered in between. Resolve and re-read the exact preference attempt while
     * holding the same cart lock used by notifications, then fail closed before validateOrder()
     * when that durable terminal disposition exists. An existing order may be reused only when
     * this exact attempt was already linked to it; cart ownership alone cannot prove that a
     * different preference created the order.
     *
     * @return mixed
     */
    public function withDeferredOrderCreationLock(
        int $cartId,
        string $preferenceId,
        callable $operation,
    ) {
        return $this->withOrderCreationLock(
            $cartId,
            function () use ($cartId, $preferenceId, $operation) {
                $transaction = $this->resolveDeferredCheckoutAttempt($cartId, $preferenceId);
                $existingOrderId = (int) \Order::getIdByCartId($cartId);
                $transactionOrderId = (int) ($transaction['merchant_order_id'] ?? 0);
                $transactionId = (int) ($transaction['id_mp_transaction'] ?? 0);
                $hasCreationProof = false;
                if ($existingOrderId > 0 && $transactionOrderId !== $existingOrderId) {
                    $order = new \Order($existingOrderId);
                    $hasCreationProof = OrderAttemptReference::matches(
                        $order,
                        $transactionId,
                        $cartId
                    );
                }

                if ($existingOrderId > 0
                    && $transactionOrderId !== $existingOrderId
                    && !$hasCreationProof
                ) {
                    throw new \DomainException('Deferred checkout attempt does not own the existing order');
                }

                if ($existingOrderId <= 0 && $transactionOrderId > 0) {
                    throw new \DomainException('Deferred checkout attempt references an unavailable order');
                }

                $paymentStatus = (string) ($transaction['payment_status'] ?? '');
                if ($existingOrderId <= 0
                    && in_array($paymentStatus, self::TERMINAL_NEGATIVE_PAYMENT_STATUSES, true)
                ) {
                    throw new \DomainException('Deferred order creation blocked by terminal payment status');
                }

                return $operation($transaction);
            }
        );
    }

    private function parseOrderResponse($orderResponseOrNotificationUrl, $maybeOrderResponse)
    {
        if (is_array($orderResponseOrNotificationUrl)) {
            return [$orderResponseOrNotificationUrl, null];
        }

        $notificationUrl = $orderResponseOrNotificationUrl;
        $orderResponse = is_array($maybeOrderResponse) ? $maybeOrderResponse : [];

        return [$orderResponse, $notificationUrl];
    }

    private function extractPaymentData($orderResponse)
    {
        $firstPayment = $this->getFirstPayment($orderResponse);

        return [
            'status' => $this->getStatus($orderResponse),
            'payment_method' => $this->getPaymentMethod($firstPayment, $orderResponse),
            'payment_type' => $this->getPaymentType($firstPayment, $orderResponse),
            'mp_payment_id' => $this->getMpPaymentId($firstPayment, $orderResponse),
            'installments' => $this->getInstallments($firstPayment, $orderResponse),
        ];
    }

    private function getFirstPayment($orderResponse)
    {
        if (empty($orderResponse['payments']) || !is_array($orderResponse['payments'])) {
            return null;
        }

        return is_array($orderResponse['payments'][0]) ? $orderResponse['payments'][0] : null;
    }

    private function getStatus($orderResponse)
    {
        $status = (string) ($orderResponse['status'] ?? 'pending');

        return $status !== '' ? $status : 'pending';
    }

    private function getPaymentMethod($firstPayment, $orderResponse)
    {
        $method = (string) (
            ($firstPayment['payment_method']['id'] ?? null)
            ?? ($firstPayment['payment_method_id'] ?? null)
            ?? ($orderResponse['payment_method_id'] ?? null)
            ?? 'unknown'
        );

        return $method !== '' ? $method : 'unknown';
    }

    private function getPaymentType($firstPayment, $orderResponse)
    {
        $type = (string) (
            ($firstPayment['payment_method']['type'] ?? null)
            ?? ($firstPayment['payment_type_id'] ?? null)
            ?? ($orderResponse['payment_type_id'] ?? null)
            ?? 'unknown'
        );

        return $type !== '' ? $type : 'unknown';
    }

    private function getMpPaymentId($firstPayment, $orderResponse)
    {
        return isset($firstPayment['references']['payment_id'])
            ? (string) $firstPayment['references']['payment_id']
            : '';
    }

    private function getInstallments($firstPayment, $orderResponse)
    {
        $installments = null;

        if (isset($firstPayment['payment_method']['installments'])) {
            $installments = (int) $firstPayment['payment_method']['installments'];
        } elseif (isset($firstPayment['installments'])) {
            $installments = (int) $firstPayment['installments'];
        } elseif (isset($orderResponse['installments'])) {
            $installments = (int) $orderResponse['installments'];
        }

        return ($installments !== null && $installments > 0) ? $installments : null;
    }

    private function getExistingTransactionId($orderResponse)
    {
        $apiOrderId = isset($orderResponse['id']) ? (string) $orderResponse['id'] : null;
        $isMockOrder = $apiOrderId !== null && strpos($apiOrderId, 'MOCK-ORDER-') === 0;
        if ($isMockOrder || $apiOrderId === null || $apiOrderId === '') {
            return 0;
        }

        return $this->transactionRepository->findExistingTransactionIdByOrderId($apiOrderId);
    }

    private function getCollectorIdFromResponse($orderResponse)
    {
        if (!isset($orderResponse['collector_id']) || $orderResponse['collector_id'] === '') {
            return null;
        }

        return (int) $orderResponse['collector_id'];
    }

    private function getCollectorIdFromSeller()
    {
        return $this->capturedCollectorId;
    }

    private function buildTransactionData(
        $idCart,
        $idCustomer,
        $apiOrderId,
        $amount,
        $mpModuleId,
        $notificationUrl,
        $isTest,
        $paymentData,
    ) {
        return [
            'cart_id' => (int) $idCart,
            'order_id' => $apiOrderId ? pSQL($apiOrderId) : null,
            'customer_id' => (int) $idCustomer,
            'total' => (float) $amount,
            'mp_module_id' => (int) $mpModuleId,
            'notification_url' => $notificationUrl ? pSQL((string) $notificationUrl) : null,
            'is_payment_test' => (int) $isTest,
            'received_webhook' => 0,
            'payment_id' => $paymentData['mp_payment_id'] !== '' ? pSQL($paymentData['mp_payment_id']) : null,
            'payment_method' => pSQL($paymentData['payment_method']),
            'payment_type' => pSQL($paymentData['payment_type']),
            'payment_status' => pSQL($paymentData['status']),
            'payment_amount' => pSQL($amount),
            'merchant_order_id' => null,
        ];
    }

    private function saveTransactionData($data, $existingId)
    {
        if ($existingId > 0) {
            return $this->transactionRepository->updateCheckoutMetadata($data, (int) $existingId)
                ? (int) $existingId
                : 0;
        }

        return $this->transactionRepository->insert($data);
    }

    protected function getPlatformData()
    {
        return [
            'environment' => [
                'platform_version' => _PS_VERSION_,
                'module_version' => MP_VERSION,
            ],
            'specific_data' => new \stdClass(),
        ];
    }

    protected function getShipmentData($cart)
    {
        $shipmentAddress = $this->getShipmentAddress($cart);

        return [
            'address' => [
                'zip_code' => $shipmentAddress['receiver_address']['zip_code'],
                'street_name' => $shipmentAddress['receiver_address']['street_name'],
                'street_number' => $shipmentAddress['receiver_address']['street_number'],
                'city' => $shipmentAddress['receiver_address']['city_name'],
                'complement' => $shipmentAddress['receiver_address']['apartment'],
                'floor' => $shipmentAddress['receiver_address']['floor'],
            ],
        ];
    }

    protected function getBillingData($cart)
    {
        $addressInvoice = new \Address((int) $cart->id_address_invoice);

        return [
            'address' => [
                'zip_code' => $addressInvoice->postcode,
                'street_name' => $addressInvoice->address1 . ' - ' . $addressInvoice->address2,
                'street_number' => '-',
                'city' => $addressInvoice->city,
                'country' => $addressInvoice->country,
            ],
        ];
    }
}
