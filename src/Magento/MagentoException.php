<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Magento;

/**
 * Raised for any Magento failure. The message is for internal logs only and
 * must never be shown to the customer or handed to the model.
 */
class MagentoException extends \RuntimeException
{
}
