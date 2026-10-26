<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Features\Billing;
use App\Models\Workspace;
use App\Services\Billing\HostedWorkspaceAccess;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\SystemAdmin\Actions\StartManagedPlan;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Filament\Support\ManagedPlan;
use Relaticle\SystemAdmin\Mail\ManagedPlanInvoiceMail;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\OverviewData;
use Tests\Helpers\StripeRecorder;

mutates(StartManagedPlan::class, ManagedPlan::class, ManagedPlanInvoiceMail::class);

const HOSTED_INVOICE_URL = 'https://invoice.stripe.com/i/acct_test/test_managed';

beforeEach(function (): void {
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');
    config()->set('services.stripe.managed_days_until_due', 30);

    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $this->stripe = StripeRecorder::install()
        ->respondTo('/v1/customers', ['id' => 'cus_managed_test', 'object' => 'customer', 'email' => 'owner@example.test', 'balance' => 0])
        ->respondTo('/v1/subscriptions', managedSubscriptionBody())
        ->respondTo('/v1/invoices/in_managed_test/send', ['id' => 'in_managed_test', 'object' => 'invoice', 'hosted_invoice_url' => HOSTED_INVOICE_URL]);
});

afterEach(function (): void {
    StripeRecorder::uninstall();
});

/** @return array<string, mixed> */
function managedSubscriptionBody(): array
{
    return [
        'id' => 'sub_managed_test',
        'object' => 'subscription',
        'customer' => 'cus_managed_test',
        'status' => 'active',
        'latest_invoice' => ['id' => 'in_managed_test', 'object' => 'invoice'],
        'items' => [
            'object' => 'list',
            'data' => [[
                'id' => 'si_managed_test',
                'object' => 'subscription_item',
                'price' => ['id' => 'price_managed_test', 'object' => 'price', 'product' => 'prod_concierge_test'],
                'quantity' => 1,
            ]],
        ],
    ];
}

function managedPlanWorkspace(): Workspace
{
    return OverviewData::workspaceOf(OverviewData::owner());
}

/** @param  array<string, mixed>  $data */
function startManagedPlanFor(Workspace $workspace, array $data): Testable
{
    return livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('startManagedPlan')->table($workspace), $data);
}

function subscriptionRequests(StripeRecorder $stripe): int
{
    return count(array_filter($stripe->requests, fn (array $request): bool => str_ends_with($request['url'], '/v1/subscriptions')));
}

it('starts a Concierge subscription billed by invoice at the configured yearly price', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    $params = $this->stripe->paramsFor('/v1/subscriptions');
    $subscription = $workspace->subscription(SubscriptionType::ManagedPending->value);

    expect($params['collection_method'])->toBe('send_invoice')
        ->and($params['days_until_due'])->toBe(30)
        ->and($params['payment_behavior'])->toBe('allow_incomplete')
        ->and($params['items'][0]['price_data']['unit_amount'])->toBe(70800)
        ->and($params['items'][0]['price_data']['product'])->toBe('prod_concierge_test')
        ->and($params['items'][0]['price_data']['recurring']['interval'])->toBe('year')
        ->and($params['metadata']['workspace_id'])->toBe((string) $workspace->getKey())
        ->and($params['metadata']['type'])->toBe('managed_pending')
        ->and($this->stripe->paramsFor('/v1/invoices/in_managed_test/send'))->toBe([])
        ->and($subscription?->stripe_id)->toBe('sub_managed_test')
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('invoices Enterprise at the amount the sysadmin types', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 32000])
        ->assertHasNoActionErrors();

    $price = $this->stripe->paramsFor('/v1/subscriptions')['items'][0]['price_data'];

    expect($price['unit_amount'])->toBe(3200000)
        ->and($price['product'])->toBe('prod_enterprise_test');
});

it('offers each managed plan its default yearly amount', function (): void {
    $workspace = managedPlanWorkspace();

    livewire(ListWorkspaces::class)
        ->mountAction(TestAction::make('startManagedPlan')->table($workspace))
        ->fillForm(['plan' => Plan::Concierge->value])
        ->assertSchemaStateSet(['amount' => 708])
        ->fillForm(['plan' => Plan::Enterprise->value])
        ->assertSchemaStateSet(['amount' => 20000]);
});

it('keeps a paused workspace paused while the managed invoice is unpaid', function (): void {
    Feature::define(Billing::class, true);
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['pro_trial_used_at' => now()->subMonth(), 'trial_ends_at' => now()->subDay()])->save();

    expect(resolve(HostedWorkspaceAccess::class)->allows($workspace))->toBeFalse();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    expect(resolve(HostedWorkspaceAccess::class)->allows($workspace->refresh()))->toBeFalse();
});

it('keeps a running trial when the managed invoice is sent', function (): void {
    $workspace = OverviewData::trial(managedPlanWorkspace());
    $endsAt = $workspace->trial_ends_at;

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasNoActionErrors();

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('mails the billing contact the hosted invoice link', function (): void {
    Mail::fake();
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708, 'billing_email' => 'finance@example.test'])
        ->assertHasNoActionErrors();

    Mail::assertQueued(ManagedPlanInvoiceMail::class, fn (ManagedPlanInvoiceMail $mail): bool => $mail->hasTo('finance@example.test')
        && str_contains($mail->render(), HOSTED_INVOICE_URL)
        && str_contains($mail->render(), '$708'));
});

it('mails nobody when the billing email is empty', function (): void {
    Mail::fake();

    startManagedPlanFor(managedPlanWorkspace(), ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    Mail::assertNothingQueued();
});

it('refuses while another managed invoice is unpaid', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(1);
});

it('refuses a customer with a Stripe balance', function (): void {
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['stripe_id' => 'cus_'.Str::ulid()])->save();
    $this->stripe->respondTo('/v1/customers', ['id' => $workspace->stripe_id, 'object' => 'customer', 'balance' => -5000]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('refuses a plan with no Stripe product configured', function (): void {
    config()->set('services.stripe.products.enterprise', null);

    startManagedPlanFor(managedPlanWorkspace(), ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('refuses a plan that is not managed when the action is called directly', function (): void {
    $administrator = SystemAdministrator::factory()->create();

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Pro, 708))
        ->toThrow(ValidationException::class);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('hides the action from a sysadmin who cannot manage customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = managedPlanWorkspace();

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('startManagedPlan')->table($workspace));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionHidden('startManagedPlan');
});

it('forbids a sysadmin without customer access from starting a plan directly', function (): void {
    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Concierge, 708))
        ->toThrow(HttpException::class);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('shows the action on the workspace page', function (): void {
    $workspace = managedPlanWorkspace();

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionVisible('startManagedPlan');
});
