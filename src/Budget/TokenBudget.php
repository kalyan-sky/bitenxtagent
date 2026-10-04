<?php

declare(strict_types=1);

namespace Bitenxt\SupportAgent\Budget;

use Bitenxt\SupportAgent\Support\Logger;

/**
 * Caps AI token spend so an attack or a sudden spike cannot run up a bill.
 *
 * Before every AI call the estimated tokens are reserved against all limits
 * at once (customer per day, whole service per hour and per day, and the
 * provider per day). If any limit would be exceeded, the reservation is
 * undone and the call is not made. After the call, the reservation is
 * corrected to the real usage the provider reported. Reserving up front
 * means a burst of simultaneous requests cannot overshoot the limits.
 *
 * If the counters cannot be reached, the budget fails closed: no AI calls.
 * A limit of 0 means "no limit".
 */
class TokenBudget
{
    /** @param array<string, int> $providerDailyLimits keyed by provider name */
    public function __construct(
        private readonly TokenCounter $counter,
        private readonly Logger $logger,
        private readonly int $customerPerDay,
        private readonly int $globalPerHour,
        private readonly int $globalPerDay,
        private readonly array $providerDailyLimits = [],
    ) {
    }

    /** @throws BudgetExceededException */
    public function reserve(string $customerKey, string $provider, int $estimate): Reservation
    {
        $now = time();
        $hour = intdiv($now, 3600);
        $day = intdiv($now, 86400);
        $dayEnds = ($day + 2) * 86400;

        // [scope, counter id, limit, expiry]
        $limits = array_filter([
            [BudgetExceededException::GLOBAL, "global-h{$hour}", $this->globalPerHour, ($hour + 2) * 3600],
            [BudgetExceededException::GLOBAL, "global-d{$day}", $this->globalPerDay, $dayEnds],
            [BudgetExceededException::CUSTOMER, "customer-d{$day}-" . substr(hash('sha256', $customerKey), 0, 32), $this->customerPerDay, $dayEnds],
            [BudgetExceededException::PROVIDER, "provider-d{$day}-{$provider}", $this->providerDailyLimits[$provider] ?? 0, $dayEnds],
        ], static fn (array $l) => $l[2] > 0);
        $limits = array_values($limits);
        if ($limits === []) {
            return new Reservation([], 0);
        }

        $counters = array_map(static fn (array $l) => ['id' => $l[1], 'expireAt' => $l[3]], $limits);
        try {
            $totals = $this->counter->add(array_map(static fn ($c) => $c + ['amount' => $estimate], $counters));
        } catch (\Throwable $e) {
            $this->logger->log('token_budget_unavailable', ['detail' => $e->getMessage()]);
            throw new BudgetExceededException(BudgetExceededException::UNAVAILABLE, 'Token counters unavailable');
        }

        $exceeded = [];
        foreach ($limits as $i => [$scope, $id, $limit]) {
            if (($totals[$i] ?? PHP_INT_MAX) > $limit) {
                $exceeded[$scope] = $id;
            }
        }
        if ($exceeded === []) {
            return new Reservation($counters, $estimate);
        }

        $this->release(new Reservation($counters, $estimate));
        // The widest limit wins: a service-wide stop beats a customer or provider limit.
        $scope = isset($exceeded[BudgetExceededException::GLOBAL]) ? BudgetExceededException::GLOBAL
            : (isset($exceeded[BudgetExceededException::CUSTOMER]) ? BudgetExceededException::CUSTOMER : BudgetExceededException::PROVIDER);
        $this->logger->log('token_budget_exceeded', [
            'scope' => $scope,
            'counter' => $exceeded[$scope],
            'provider' => $provider,
            'customer' => Logger::pseudonym($customerKey),
        ]);

        throw new BudgetExceededException($scope, 'Token limit reached: ' . $exceeded[$scope]);
    }

    /** Corrects the reservation to what the call really used. */
    public function settle(Reservation $reservation, int $actualTokens): void
    {
        $this->adjust($reservation, $actualTokens - $reservation->estimate);
    }

    /** Gives back a reservation whose call never happened or failed. */
    public function release(Reservation $reservation): void
    {
        $this->adjust($reservation, -$reservation->estimate);
    }

    private function adjust(Reservation $reservation, int $delta): void
    {
        if ($delta === 0 || $reservation->counters === []) {
            return;
        }
        try {
            $this->counter->add(array_map(static fn ($c) => $c + ['amount' => $delta], $reservation->counters));
        } catch (\Throwable $e) {
            // The reservation (an over-estimate) stays counted: safe for cost.
            $this->logger->log('token_budget_unavailable', ['detail' => $e->getMessage()]);
        }
    }
}
