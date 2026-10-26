<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Filament\Pages\Billing;
use App\Mail\ProEndedMail;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(ProEndedMail::class);

function proEndedWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => User::factory()->create()->id,
        'hosted_free_grandfathered_at' => null,
        ...$attributes,
    ]);
}

it('tells the owner the trial ended, the workspace is paused, and how to reopen it', function (): void {
    $workspace = proEndedWorkspace();

    $mail = ProEndedMail::afterTrial($workspace);

    $mail->assertHasSubject(__('mail.pro_ended.trial_ended.subject'));
    $mail->assertSeeInHtml(__('mail.pro_ended.trial_ended.heading', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.restore'));
    $mail->assertSeeInText(__('mail.pro_ended.cta').': '.Billing::getUrl(panel: 'app', tenant: $workspace));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('names the ended subscription rather than a trial', function (): void {
    $mail = ProEndedMail::afterSubscription(proEndedWorkspace());

    $mail->assertHasSubject(__('mail.pro_ended.subscription_ended.subject'));
    $mail->assertSeeInHtml(__('mail.pro_ended.subscription_ended.heading', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml('mail.pro_ended.');

    expect($mail->envelope()->subject)->not->toStartWith('mail.');
});

it('names the managed plan that ended', function (Plan $plan, string $label): void {
    $mail = ProEndedMail::afterManagedPlan(proEndedWorkspace(), $plan);

    $mail->assertHasSubject("Your {$label} plan has ended");
    $mail->assertSeeInHtml("{$label} for Acme has ended");
    $mail->assertSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
    $mail->assertDontSeeInHtml('mail.pro_ended.');
})->with([
    'concierge' => [Plan::Concierge, 'Cloud Concierge'],
    'enterprise' => [Plan::Enterprise, 'Enterprise'],
]);

it('tells a grandfathered workspace it is back on Cloud Free, not paused', function (): void {
    $mail = ProEndedMail::afterTrial(proEndedWorkspace(['hosted_free_grandfathered_at' => now()->subYear()]));

    $mail->assertSeeInHtml(__('mail.pro_ended.grandfathered', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.pro_ended.cta_grandfathered'));
    $mail->assertDontSeeInHtml(__('mail.pro_ended.paused', ['workspace' => 'Acme']));
});

it('says email and calendar sync are paused only when the workspace has a mailbox', function (): void {
    $workspace = proEndedWorkspace();

    ProEndedMail::afterTrial($workspace)->assertDontSeeInHtml(__('mail.pro_ended.mailbox_sync_paused'));

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create(['workspace_id' => $workspace->id]));

    ProEndedMail::afterTrial($workspace)->assertSeeInHtml(__('mail.pro_ended.mailbox_sync_paused'));
});
