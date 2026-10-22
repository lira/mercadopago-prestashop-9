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
if (!defined('_PS_VERSION_')) {
    exit;
}

// Necessário: esta classe base global precisa ser incluída antes que o
// PHP-Scoper possa criar seu alias no artefato de release.
require_once __DIR__ . '/AbstractMercadopagoModuleFrontController.php';

use MercadoPago\Client\MPApi;
use MercadoPago\Service\Order\CardOrderService;

class MercadopagoCardModuleFrontController extends AbstractMercadopagoModuleFrontController
{
    /**
     * Process payment form submission
     */
    public function postProcess()
    {
        $mpOrder = new CardOrderService($this->context);

        try {
            $custom_info = Tools::getValue('mercadopago_custom');

            if (empty($custom_info) || !is_array($custom_info)) {
                throw new Exception('Form data not received from the frontend');
            }

            $apiResult = $mpOrder->createMPOrder($this->context->cart, $custom_info);
            $httpStatus = $apiResult['status'] ?? 0;
            $mpOrderResponse = $apiResult['response'] ?? [];

            $isApiSuccess = $httpStatus >= 200 && $httpStatus < 300;
            $isOrderRejected = isset($mpOrderResponse['status']) && $mpOrderResponse['status'] === 'failed';

            if (!$isApiSuccess) {
                PrestaShopLogger::addLog(
                    '[MercadoPago Card] API error - status: ' . $httpStatus . ' - ' . (MPApi::extractApiErrorMessage($mpOrderResponse) ?? 'unknown error'),
                    3,
                    null,
                    'Cart',
                    (int) $this->context->cart->id,
                    true
                );
            }

            if ($isApiSuccess && is_array($mpOrderResponse) && !$isOrderRejected) {
                $cart = $this->context->cart;
                $order = $this->persistTransactionAndCreateOrder(
                    $mpOrder,
                    $cart,
                    $mpOrderResponse,
                    $this->resolveCardOrderState($mpOrderResponse)
                );

                $uri = __PS_BASE_URI__ . 'index.php?' . http_build_query([
                    'controller' => 'order-confirmation',
                    'id_cart' => $order->id_cart,
                    'key' => $order->secure_key,
                    'id_order' => $order->id,
                    'id_module' => $this->module->id,
                    'checkout_type' => 'custom',
                    'payment_id' => $mpOrderResponse['id'] ?? '',
                    'payment_status' => $mpOrderResponse['status'] ?? '',
                ]);

                $mpOrder->clearCartAfterOrder($this->context);
                Tools::redirect($uri);

                return;
            }

            if ($isApiSuccess && $isOrderRejected) {
                $statusDetail = $mpOrderResponse['status_detail'] ?? null;
                if ($statusDetail) {
                    $this->context->cookie->__set('redirect_message', str_replace('_', ' ', $statusDetail));
                }
            } else {
                $message = MPApi::extractApiErrorMessage($mpOrderResponse);
                if (!empty($message)) {
                    $this->context->cookie->__set('redirect_message', $message);
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MercadoPago Card] Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            $this->errors[] = $e->getMessage();
        }

        $mpOrder->removeCartRuleFromCart($this->context->cart);
        $mpOrder->redirectError();
    }

    /**
     * Resolve the initial PrestaShop state for the synchronous card response.
     */
    private function resolveCardOrderState(array $mpOrderResponse): int
    {
        $status = $mpOrderResponse['status'] ?? '';
        if ($status === 'processed' || $status === 'approved') {
            $mpState = (int) Configuration::get('MERCADOPAGO_STATUS_1');

            return $mpState > 0 ? $mpState : (int) Configuration::get('PS_OS_PAYMENT');
        }

        if ($status === 'in_process' || $status === 'authorized') {
            $mpState = (int) Configuration::get('MERCADOPAGO_STATUS_0');

            return $mpState > 0 ? $mpState : (int) Configuration::get('PS_OS_PREPARATION');
        }

        $mpState = (int) Configuration::get('MERCADOPAGO_STATUS_7');

        return $mpState > 0 ? $mpState : (int) Configuration::get('PS_OS_PREPARATION');
    }
}
