<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Brings an order in line with its Klarna hosted payment page session, asked
 * from Klarna itself:
 * - COMPLETED: Klarna placed its order; when Order Management shows it
 *   authorized for the order's amount and currency, the Magento order is
 *   authorized (OrderPayment::authorize) and waits for the shop's capture;
 * - CANCELLED, FAILED, ERROR, DISABLED: cancelled;
 * - WAITING, BACK: nobody finished on Klarna's page (NOT_STARTED);
 * - IN_PROGRESS, MANUAL_ID_CHECK: nothing yet.
 */
class OrderUpdater
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment
    ) {
    }

    /**
     * @return string A ReturnResult state
     * @throws LocalizedException When Klarna doesn't answer
     */
    public function update(Order $order): string
    {
        $sessionId = (string) $order->getPayment()?->getAdditionalInformation(Payment::HPP_SESSION_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $sessionId === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $storeId = (int) $order->getStoreId();
        $session = $this->api->get('/hpp/v1/sessions/' . rawurlencode($sessionId), $storeId);

        return match ((string) ($session['status'] ?? '')) {
            'COMPLETED' => $this->completed($order, (string) ($session['order_id'] ?? ''), $storeId),
            'CANCELLED', 'FAILED', 'ERROR', 'DISABLED' => $this->failed($order, (string) $session['status']),
            'WAITING', 'BACK' => ReturnResult::NOT_STARTED,
            default => ReturnResult::PENDING,
        };
    }

    /**
     * Nobody finished on Klarna's page: its session is switched off (so it
     * can't be finished later) and the order cancelled.
     */
    public function abandon(Order $order): void
    {
        $sessionId = (string) $order->getPayment()->getAdditionalInformation(Payment::HPP_SESSION_ID);
        try {
            $this->api->delete('/hpp/v1/sessions/' . rawurlencode($sessionId), (int) $order->getStoreId());
        } catch (LocalizedException) {
            // Already over: nothing to switch off.
        }
        $this->failed($order, (string) __('the customer came back without paying'));
    }

    /**
     * @throws LocalizedException
     */
    private function completed(Order $order, string $klarnaOrderId, int $storeId): string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $klarnaOrderId)) {
            return ReturnResult::PENDING;
        }
        $klarnaOrder = $this->api->get('/ordermanagement/v1/orders/' . $klarnaOrderId, $storeId);
        $currency = (string) $order->getOrderCurrencyCode();
        $expected = OrderLines::minor((float) $order->getGrandTotal(), $currency);
        if (($klarnaOrder['purchase_currency'] ?? '') !== $currency || (int) ($klarnaOrder['order_amount'] ?? -1) !== $expected) {
            $this->orderPayment->flag(
                $order,
                (string) __('Klarna reports an order of a different amount; check it in the Klarna merchant portal.'),
                sprintf(
                    'Klarna: %s %s, expected %d %s',
                    $klarnaOrder['order_amount'] ?? '?',
                    $klarnaOrder['purchase_currency'] ?? '?',
                    $expected,
                    $currency
                )
            );

            return ReturnResult::PENDING;
        }
        if (!in_array($klarnaOrder['status'] ?? '', ['AUTHORIZED', 'PART_CAPTURED', 'CAPTURED'], true)) {
            return ReturnResult::PENDING;
        }
        $order->getPayment()->setAdditionalInformation(Payment::KLARNA_ORDER_ID, $klarnaOrderId);
        $this->orderPayment->authorize($order, $klarnaOrderId);

        return ReturnResult::PAID;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('Klarna payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
