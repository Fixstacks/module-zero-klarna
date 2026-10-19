<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero_Klarna: Klarna (pay now, pay later, in instalments) on Klarna's
 * hosted payment page, for Zero Checkout. See README.md.
 */
use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Zero_Klarna', __DIR__);
