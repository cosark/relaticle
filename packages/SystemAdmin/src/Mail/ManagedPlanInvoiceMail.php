<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Mail;

use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Number;

final class ManagedPlanInvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Workspace $workspace,
        public Plan $plan,
        public string $hostedInvoiceUrl,
        public int $amount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.managed_plan_invoice.subject', ['plan' => $this->planName()]));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'system-admin::mail.managed-plan-invoice',
            with: [
                'planName' => $this->planName(),
                'formattedAmount' => Number::currency($this->amount, 'USD', precision: 0),
            ],
        );
    }

    private function planName(): string
    {
        return match ($this->plan) {
            Plan::Concierge => __('billing.plans.cloud_concierge'),
            Plan::Enterprise => __('billing.plans.enterprise'),
            Plan::Free, Plan::Pro => $this->plan->getLabel(),
        };
    }
}
