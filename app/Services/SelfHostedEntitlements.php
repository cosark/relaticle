<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Entitlements;
use App\Models\Workspace;

final readonly class SelfHostedEntitlements implements Entitlements
{
    public function metersCredits(Workspace $workspace): bool
    {
        return false;
    }

    public function creditAllowance(Workspace $workspace): int
    {
        return 0;
    }
}
