<x-mail::message :reason="__('mail.footer.reason.billing_contact', ['workspace' => $workspace->name])">
<x-slot:preheader>{{ __('mail.managed_plan_invoice.preheader', ['plan' => $planName, 'workspace' => $workspace->name]) }}</x-slot:preheader>
# {{ __('mail.managed_plan_invoice.heading', ['plan' => $planName]) }}

{{ __('mail.managed_plan_invoice.line', ['amount' => $formattedAmount, 'workspace' => $workspace->name, 'plan' => $planName]) }}

<x-mail::button :url="$hostedInvoiceUrl">
{{ __('mail.managed_plan_invoice.cta') }}
</x-mail::button>
</x-mail::message>
