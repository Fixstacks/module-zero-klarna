<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\OrderFactory;
use Zero\Klarna\Model\Payment;
use Zero\Checkout\Model\RedirectPayment\Webhooks;

/**
 * Klarna's status_update callback (?order=<increment id>, a JSON POST with
 * the hosted payment page session): for a customer who finished on Klarna's
 * page and never came back. The session must be the order's own; its state
 * is then asked from Klarna (OrderUpdater, now or from the queue: Zero
 * Checkout > Payment Webhooks), so a forged call can't change anything.
 *
 * 200 when handled or not ours; 503 when Klarna can't be asked right now.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly OrderFactory $orderFactory,
        private readonly Webhooks $webhooks
    ) {
    }

    /**
     * @return Raw
     */
    public function execute()
    {
        $result = $this->rawFactory->create()->setContents('');
        $reference = (string) $this->request->getParam('order', '');
        $order = $reference === '' ? null : $this->orderFactory->create()->loadByIncrementId($reference);
        if (!$order || !$order->getId() || $order->getPayment()?->getMethod() !== Payment::CODE
            || $order->getPayment()->getAdditionalInformation(Payment::HPP_SESSION_ID) !== $this->sessionId()
        ) {
            return $result->setHttpResponseCode(200);
        }
        try {
            $this->webhooks->handle($order, ['session_id' => $this->sessionId()]);
        } catch (LocalizedException) {
            return $result->setHttpResponseCode(503);
        }

        return $result->setHttpResponseCode(200);
    }

    private function sessionId(): string
    {
        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;
        $body = json_decode((string) $request->getContent(), true);

        return is_array($body) ? (string) ($body['session_id'] ?? '') : '';
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Nothing here trusts the request: the state is asked from Klarna.
     *
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
