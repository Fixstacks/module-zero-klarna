<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Klarna\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Klarna's API region: where the merchant account is.
 */
class Region implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'eu', 'label' => __('Europe')],
            ['value' => 'na', 'label' => __('North America')],
            ['value' => 'oc', 'label' => __('Oceania')],
        ];
    }
}
