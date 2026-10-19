<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Klarna\Model\OrderUpdater;
use Zero\Klarna\Model\Payment;

/**
 * Klarna's cancel and back URLs (?order=<increment id>): the customer left
 * Klarna's page. The session is switched off, the order cancelled and the
 * cart restored.
 */
class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $order = $this->returnResult->lastOrder(Payment::CODE, (string) $this->request->getParam('order'));
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        $this->orderUpdater->abandon($order);

        return $this->returnResult->redirect(ReturnResult::FAILED, 'Klarna');
    }
}
