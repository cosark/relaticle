<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Entitlements;
use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Workspace;

final readonly class PlanEntitlements implements Entitlements
{
    public function metersCredits(Workspace $workspace): bool
    {
        return true;
    }

    public function creditAllowance(Workspace $workspace): int
    {
        $workspace->loadMissing('subscriptions');

        if (! $workspace->plan->isManaged() && $workspace->subscription()?->pastDue() === true) {
            return Plan::Free->credits();
        }

        if ($workspace->billingStatus() === BillingStatus::TrialEnded) {
            return Plan::Free->credits();
        }

        return $workspace->plan->credits();
    }
}
