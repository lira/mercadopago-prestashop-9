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

use MercadoPago\Service\Order\AbstractOrderService;
use MercadoPago\Service\Order\Exception\FinancialCartMismatchException;
use MercadoPago\Service\Order\OrderAttemptReference;

abstract class AbstractMercadopagoModuleFrontController extends ModuleFrontController
{
    /**
     * Persist the MP transaction and create the PrestaShop order while holding the same
     * cart-scoped lock used by webhook processing.
     */
    protected function persistTransactionAndCreateOrder(
        AbstractOrderService $orderService,
        Cart $cart,
        array $mpOrderResponse,
        ?int $orderState = null,
    ): Order {
        return $orderService->withOrderCreationLock(
            (int) $cart->id,
            function () use ($orderService, $cart, $mpOrderResponse, $orderState): Order {
                $orderResponseForPersistence = $mpOrderResponse;
                $orderResponseForPersistence['total_amount'] = $orderService
                    ->getCapturedTransactionAmount($cart);
                $transactionId = $orderService->saveCreateOrderData(
                    $cart,
                    $orderResponseForPersistence
                );
                if ($transactionId <= 0) {
                    throw new RuntimeException('Could not persist checkout transaction');
                }

                $orderCreationStarted = false;
                $newOrderCreationGuarded = false;
                try {
                    // The persisted request amount may be reused for a new
                    // attempt, but an idempotent API replay must prove that its
                    // own canonical amount still matches that captured request
                    // before any ownership reservation or local order creation.
                    $orderService->assertCapturedOrderResponseMatches(
                        $cart,
                        $mpOrderResponse
                    );

                    // Persist the exact reservation before the local financial
                    // transaction. If a transient pre-order failure rolls back,
                    // webhooks must wait for checkout retry instead of creating
                    // a full-price order from the now-neutral cart.
                    if (!$orderService->reserveTransactionOrderCreationForAttempt(
                        (int) $cart->id,
                        $transactionId
                    )) {
                        throw new RuntimeException('Could not reserve checkout order ownership');
                    }

                    // validateOrder() can emit hooks and e-mails before it
                    // returns. Persist the manual-reconciliation marker in
                    // autocommit mode, before START TRANSACTION, so a process
                    // death cannot roll it back together with the order and
                    // then repeat those side effects on retry.
                    if ((int) Order::getIdByCartId((int) $cart->id) <= 0) {
                        if (!$orderService->markTransactionOrderCreationUncertainForAttempt(
                            (int) $cart->id,
                            $transactionId
                        )) {
                            throw new RuntimeException('Could not guard checkout order creation');
                        }
                        $newOrderCreationGuarded = true;
                    }

                    return $orderService->withFinancialStateTransaction(
                        $cart,
                        function (Cart $lockedCart) use (
                            &$orderCreationStarted,
                            $newOrderCreationGuarded,
                            $orderService,
                            $mpOrderResponse,
                            $orderResponseForPersistence,
                            $orderState,
                            $transactionId
                        ): Order {
                            $orderService->prepareCapturedCartRuleForOrder(
                                $lockedCart,
                                $mpOrderResponse
                            );

                            $needsNewOrder = (int) Order::getIdByCartId(
                                (int) $lockedCart->id
                            ) <= 0;
                            if ($needsNewOrder && !$newOrderCreationGuarded) {
                                throw new RuntimeException('Checkout order presence changed before validation');
                            }
                            $orderCreationStarted = $needsNewOrder;
                            $order = $this->getOrCreateOrder(
                                $lockedCart,
                                $orderResponseForPersistence,
                                $orderState,
                                $transactionId
                            );
                            $orderService->assertCapturedOrderMatches(
                                $lockedCart,
                                $order
                            );
                            if (!$orderService->updateTransactionOrderIdForAttempt(
                                (int) $order->id,
                                (int) $lockedCart->id,
                                $transactionId
                            )) {
                                throw new RuntimeException('Could not link checkout order');
                            }

                            $orderService->finalizeCapturedCartRuleForOrder($lockedCart);

                            return $order;
                        }
                    );
                } catch (FinancialCartMismatchException $exception) {
                    // Once validateOrder() started, preserve the uncertain
                    // marker because hooks/e-mails may already have escaped.
                    // A mismatch detected before that point is safe to classify
                    // as an invalid financial snapshot. An exact-attempt order
                    // may already exist after an interrupted webhook link; keep
                    // its reservation so notification retry can finish linking.
                    $existingOrderId = (int) Order::getIdByCartId((int) $cart->id);
                    $hasExactExistingOrder = false;
                    if ($existingOrderId > 0) {
                        $existingOrder = new Order($existingOrderId);
                        $hasExactExistingOrder = (bool) $existingOrder->id
                            && OrderAttemptReference::matches(
                                $existingOrder,
                                $transactionId,
                                (int) $cart->id
                            );
                    }

                    // The transaction callback updates this flag by reference.
                    // @phpstan-ignore-next-line
                    if (!$orderCreationStarted && !$hasExactExistingOrder) {
                        if (!$orderService->invalidateTransactionOrderCreationForAttempt(
                            (int) $cart->id,
                            $transactionId
                        )) {
                            throw new RuntimeException('Could not invalidate mismatched checkout attempt', 0, $exception);
                        }
                    }

                    throw $exception;
                } catch (Throwable $exception) {
                    // The transaction callback updates this flag by reference.
                    // @phpstan-ignore-next-line
                    if ($orderCreationStarted) {
                        if (!$newOrderCreationGuarded) {
                            try {
                                $orderService->markTransactionOrderCreationUncertainForAttempt(
                                    (int) $cart->id,
                                    $transactionId
                                );
                            } catch (Throwable) {
                                PrestaShopLogger::addLog(
                                    'Unable to mark an uncertain Mercado Pago order creation attempt',
                                    3
                                );
                            }
                        }
                    } elseif ($newOrderCreationGuarded) {
                        try {
                            $orderService->restoreTransactionOrderCreationReservationForAttempt(
                                (int) $cart->id,
                                $transactionId
                            );
                        } catch (Throwable) {
                            PrestaShopLogger::addLog(
                                'Unable to restore a retryable Mercado Pago order creation attempt',
                                3
                            );
                        }
                    }

                    throw $exception;
                }
            }
        );
    }

    /**
     * Returns the PrestaShop order for the cart, creating it via validateOrder if it does not exist yet.
     *
     * By default the order is created with PS_OS_PREPARATION (pending) since payment confirmation
     * is asynchronous via webhook. Subclasses with synchronous status (e.g. Yape) can pass an
     * explicit state to override this default.
     *
     * @param Cart $cart
     * @param array $mpOrderResponse
     * @param int|null $orderState initial PS order state; defaults to PS_OS_PREPARATION when null
     *
     * @return Order
     *
     * @throws Exception
     */
    protected function getOrCreateOrder(
        Cart $cart,
        array $mpOrderResponse,
        ?int $orderState = null,
        int $transactionId = 0,
    ): Order {
        $orderId = (int) Order::getIdByCartId((int) $cart->id);

        if ($orderId <= 0) {
            $pendingState = (int) Configuration::get('MERCADOPAGO_STATUS_7');
            $resolvedState = $orderState ?? ($pendingState > 0 ? $pendingState : (int) Configuration::get('PS_OS_PREPARATION'));
            $amount = (float) ($mpOrderResponse['total_amount'] ?? $cart->getOrderTotal());
            $customer = new Customer((int) $cart->id_customer);

            if (!method_exists($this->module, 'validateOrder')) {
                throw new RuntimeException('Mercado Pago module cannot validate the order');
            }

            $this->module->validateOrder(
                (int) $cart->id,
                $resolvedState,
                $amount,
                $this->module->displayName,
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key,
                null,
                OrderAttemptReference::fromAttempt($transactionId, (int) $cart->id)
            );

            $orderId = (int) Order::getIdByCartId((int) $cart->id);
        }

        if ($orderId <= 0) {
            throw new Exception('Order could not be created');
        }

        return new Order($orderId);
    }
}
