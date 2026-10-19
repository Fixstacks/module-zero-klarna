<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Klarna\Model\Payment;

/**
 * Klarna is offered only where its page would take the purchase.
 */
class PaymentTest extends TestCase
{
    public function testSellsInTheCountrysOwnCurrencyFromItsRegion(): void
    {
        $this->assertTrue(Payment::sells('DE', 'EUR', 'eu'));
        $this->assertTrue(Payment::sells('SE', 'SEK', 'eu'));
        $this->assertTrue(Payment::sells('US', 'USD', 'na'));
    }

    public function testNotInAnotherCurrencyOrFromAnotherRegion(): void
    {
        $this->assertFalse(Payment::sells('DE', 'USD', 'eu'));
        $this->assertFalse(Payment::sells('US', 'USD', 'eu'));
        $this->assertFalse(Payment::sells('UA', 'UAH', 'eu'));
    }

    public function testWithoutACountryTheCurrencyDecides(): void
    {
        $this->assertTrue(Payment::sells('', 'EUR', 'eu'));
        $this->assertFalse(Payment::sells('', 'USD', 'eu'));
    }
}
