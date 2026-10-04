<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

/** A token limit would be exceeded, so the AI provider must not be called. */
final class BudgetExceededException extends \RuntimeException
{
    public const CUSTOMER = 'customer';   // this customer's daily limit
    public const GLOBAL = 'global';       // the whole service's hourly or daily limit
    public const PROVIDER = 'provider';   // one provider's daily limit: try the next provider
    public const UNAVAILABLE = 'unavailable'; // counters unreachable: fail closed

    public function __construct(public readonly string $scope, string $message)
    {
        parent::__construct($message);
    }
}
