<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Enums\Plan;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Relaticle\SystemAdmin\Actions\StartManagedPlan;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class ManagedPlan
{
    public static function startAction(): Action
    {
        return Action::make('startManagedPlan')
            ->label('Start managed plan')
            ->icon('heroicon-o-document-currency-dollar')
            ->authorize('manageManagedPlan')
            ->modalHeading('Start a managed plan?')
            ->modalDescription('Stripe emails a yearly invoice. The plan switches on when it is paid.')
            ->modalSubmitActionLabel('Send invoice')
            ->schema([
                Select::make('plan')
                    ->options(self::planOptions())
                    ->default(Plan::Concierge->value)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set): mixed => $set('amount', Plan::tryFrom((string) $state)?->defaultYearlyPrice()))
                    ->required(),
                TextInput::make('amount')
                    ->label('Yearly amount (USD)')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->prefix('$')
                    ->default(Plan::Concierge->defaultYearlyPrice())
                    ->required(),
                TextInput::make('billing_email')
                    ->label('Billing email')
                    ->helperText('Optional. Receives the invoice link too. The owner stays the Stripe customer.')
                    ->email(),
            ])
            ->action(function (array $data, Workspace $record): void {
                $administrator = auth('sysadmin')->user();

                abort_unless($administrator instanceof SystemAdministrator, 403);

                try {
                    resolve(StartManagedPlan::class)->execute(
                        $administrator,
                        $record,
                        Plan::from($data['plan']),
                        (int) $data['amount'],
                        $data['billing_email'] ?: null,
                    );
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), 'mountedActions.0.data.'));
                }

                Notification::make()->title('Invoice sent')->success()->send();
            });
    }

    /** @return array<string, string> */
    private static function planOptions(): array
    {
        return collect(Plan::cases())
            ->filter(fn (Plan $plan): bool => $plan->isManaged())
            ->mapWithKeys(fn (Plan $plan): array => [$plan->value => $plan->getLabel()])
            ->all();
    }
}
