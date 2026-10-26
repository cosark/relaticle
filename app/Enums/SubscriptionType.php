<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionType: string
{
    case Default = 'default';
    case Managed = 'managed';
    case ManagedPending = 'managed_pending';
}
