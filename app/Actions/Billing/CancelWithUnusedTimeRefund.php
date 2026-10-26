<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Invoice;
use UnexpectedValueException;

final readonly class CancelWithUnusedTimeRefund
{
    public function execute(Subscription $subscription, string $idempotencyKey): int
    {
        $refunded = $this->refundUnusedTime($subscription, $idempotencyKey);

        $subscription->noProrate()->cancelNow();

        return $refunded;
    }

    private function refundUnusedTime(Subscription $subscription, string $idempotencyKey): int
    {
        $invoice = Cashier::stripe()->invoices->all([
            'subscription' => $subscription->stripe_id,
            'status' => 'paid',
            'limit' => 1,
        ])->data[0] ?? null;

        if (! $invoice instanceof Invoice) {
            return 0;
        }

        $amount = $this->unusedAmount($invoice);

        if ($amount === 0) {
            return 0;
        }

        Cashier::stripe()->refunds->create([
            'payment_intent' => $this->paymentIntentOf($invoice),
            'amount' => $amount,
        ], ['idempotency_key' => $idempotencyKey]);

        return $amount;
    }

    private function unusedAmount(Invoice $invoice): int
    {
        $paid = (int) $invoice->amount_paid;

        if ($paid === 0) {
            return 0;
        }

        $period = $invoice->lines->data[0]->period ?? null;

        if ($period === null || $period->end <= $period->start) {
            throw new UnexpectedValueException("Invoice {$invoice->id} has no billing period to refund against.");
        }

        $remaining = $period->end - now()->getTimestamp();

        return max(0, min($paid, intdiv($paid * $remaining, $period->end - $period->start)));
    }

    private function paymentIntentOf(Invoice $invoice): string
    {
        $payment = Cashier::stripe()->invoicePayments->all(['invoice' => $invoice->id])->data[0]->payment ?? null;
        $intent = $payment->payment_intent ?? null;

        if (! is_string($intent)) {
            throw new UnexpectedValueException("Invoice {$invoice->id} has no payment intent to refund.");
        }

        return $intent;
    }
}
