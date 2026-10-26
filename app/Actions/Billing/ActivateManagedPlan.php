<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\Chat\Services\CreditService;

final readonly class ActivateManagedPlan
{
    public function __construct(
        private CreditService $credits,
        private CancelWithUnusedTimeRefund $cancelWithRefund,
        private RememberManagedRenewal $rememberRenewal,
    ) {}

    public function execute(Workspace $workspace, string $stripeSubscriptionId, string $invoiceId): void
    {
        $pending = $this->localSubscription($workspace, $stripeSubscriptionId);

        if ($pending->type === SubscriptionType::Managed->value) {
            return;
        }

        $stripeSubscription = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId);
        $product = $stripeSubscription->items->data[0]->price->product ?? null;
        $plan = Plan::fromStripeProduct(is_string($product) ? $product : null);

        if (! $plan instanceof Plan) {
            $this->refuse('product is not a managed plan', $workspace, $stripeSubscriptionId, $invoiceId);

            return;
        }

        if (($stripeSubscription->metadata->workspace_id ?? null) !== (string) $workspace->getKey()) {
            $this->refuse('workspace mismatch', $workspace, $stripeSubscriptionId, $invoiceId);

            return;
        }

        $this->cancelReplaced($workspace, $invoiceId);

        Cashier::stripe()->subscriptions->update($stripeSubscriptionId, [
            'metadata' => ['type' => SubscriptionType::Managed->value],
        ]);

        if (! $this->grant($workspace, $stripeSubscriptionId, $plan)) {
            return;
        }

        $workspace->refresh();

        $this->rememberRenewal->execute($stripeSubscription->toArray());

        Log::info('Managed plan switched on', [
            'workspace_id' => $workspace->getKey(),
            'plan' => $plan->value,
            'subscription_id' => $stripeSubscriptionId,
            'invoice_id' => $invoiceId,
        ]);
    }

    private function localSubscription(Workspace $workspace, string $stripeSubscriptionId): Subscription
    {
        return $this->subscriptionsOf($workspace)->where('stripe_id', $stripeSubscriptionId)->firstOrFail();
    }

    /** @return Builder<Subscription> */
    private function subscriptionsOf(Workspace $workspace): Builder
    {
        return Subscription::query()->where($workspace->getForeignKey(), $workspace->getKey());
    }

    private function refuse(string $reason, Workspace $workspace, string $stripeSubscriptionId, string $invoiceId): void
    {
        Log::warning("Managed plan activation ignored: {$reason}", [
            'workspace_id' => $workspace->getKey(),
            'subscription_id' => $stripeSubscriptionId,
            'invoice_id' => $invoiceId,
        ]);
    }

    private function cancelReplaced(Workspace $workspace, string $invoiceId): void
    {
        $replaced = $this->subscriptionsOf($workspace)
            ->whereIn('type', [SubscriptionType::Default->value, SubscriptionType::Managed->value])
            ->get()
            ->filter(fn (Subscription $subscription): bool => $subscription->valid());

        foreach ($replaced as $subscription) {
            $this->cancelWithRefund->execute($subscription, "managed-switch-{$invoiceId}-{$subscription->stripe_id}");
        }
    }

    private function grant(Workspace $workspace, string $stripeSubscriptionId, Plan $plan): bool
    {
        return DB::transaction(function () use ($workspace, $stripeSubscriptionId, $plan): bool {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace)->lockForUpdate()->firstOrFail();

            $subscription = $this->localSubscription($workspace, $stripeSubscriptionId);

            if ($subscription->type === SubscriptionType::Managed->value) {
                return false;
            }

            $subscription->forceFill(['type' => SubscriptionType::Managed->value])->save();

            $locked->forceFill(['plan' => $plan])->save();

            $this->credits->resetPeriod($locked);

            return true;
        });
    }
}
