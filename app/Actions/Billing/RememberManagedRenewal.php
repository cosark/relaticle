<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use Illuminate\Support\Facades\Cache;

final readonly class RememberManagedRenewal
{
    public const string CACHE_PREFIX = 'managed-renewal:';

    /** @param  array<string, mixed>  $stripeSubscription */
    public function execute(array $stripeSubscription): void
    {
        $id = $stripeSubscription['id'] ?? null;
        $item = $stripeSubscription['items']['data'][0] ?? [];
        $renewsAt = $item['current_period_end'] ?? null;
        $amount = $item['price']['unit_amount'] ?? null;
        $currency = $item['price']['currency'] ?? null;

        if (! is_string($id) || ! is_int($renewsAt) || ! is_int($amount) || ! is_string($currency)) {
            return;
        }

        Cache::forever(self::CACHE_PREFIX.$id, [
            'renews_at' => $renewsAt,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }
}
