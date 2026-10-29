<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Workspace;

interface Entitlements
{
    public function metersCredits(Workspace $workspace): bool;

    public function creditAllowance(Workspace $workspace): int;
}
