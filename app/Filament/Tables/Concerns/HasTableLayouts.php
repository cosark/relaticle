<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\TableLayouts\CreateTableLayout;
use App\Actions\TableLayouts\DeleteTableLayout;
use App\Actions\TableLayouts\SetDefaultTableLayout;
use App\Actions\TableLayouts\UpdateTableLayout;
use App\Data\TableStateData;
use App\Models\TableLayout;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

trait HasTableLayouts
{
    /** @var Collection<int, TableLayout>|null */
    private ?Collection $visibleTableLayouts = null;

    /**
     * @return Collection<int, TableLayout>
     */
    protected function getVisibleTableLayouts(): Collection
    {
        return $this->visibleTableLayouts ??= TableLayout::query()
            ->visibleTo($this->tableLayoutUser(), $this->tableLayoutWorkspace()->getKey(), $this->tableEntity())
            ->with('user:id,name')
            ->orderByRaw('lower(name)')
            ->get();
    }

    protected function getActiveTableLayout(): ?TableLayout
    {
        return $this->getVisibleTableLayouts()->find($this->activeTableLayoutId);
    }

    public function isTableLayoutModified(): bool
    {
        return $this->activeTableLayoutId !== null
            && $this->currentTableState()->withoutWidths()->hash() !== $this->appliedTableLayoutHash;
    }

    protected function canUpdateActiveTableLayout(): bool
    {
        $layout = $this->getActiveTableLayout();

        return $layout instanceof TableLayout && $this->tableLayoutUser()->can('update', $layout);
    }

    public function applySavedLayout(string $layoutId): void
    {
        $layout = $this->visibleTableLayout($layoutId) ?? abort(404);

        $this->applyTableState($layout->state);
        $this->activeTableLayoutId = $layout->getKey();
        $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
    }

    public function revertTableLayout(): void
    {
        if ($this->activeTableLayoutId !== null) {
            $this->applySavedLayout($this->activeTableLayoutId);
        }
    }

    public function saveTableLayout(): void
    {
        $layout = $this->getActiveTableLayout() ?? abort(404);

        $layout = resolve(UpdateTableLayout::class)->execute($this->tableLayoutUser(), $layout, ['state' => $this->currentTableState()]);
        $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
        $this->visibleTableLayouts = null;

        Notification::make()->success()->title(__('filament/panel.tables.layouts.saved', ['name' => $layout->name]))->send();
    }

    public function stopSharingTableLayout(): void
    {
        $this->setTableLayoutSharing(false);
    }

    public function toggleDefaultTableLayout(): void
    {
        $layout = $this->getActiveTableLayout() ?? abort(404);
        $default = $this->defaultTableLayoutId === $layout->getKey() ? null : $layout;

        resolve(SetDefaultTableLayout::class)->execute($this->tableLayoutUser(), $this->tableLayoutWorkspace(), $this->tableEntity(), $default);
        $this->defaultTableLayoutId = $default?->getKey();
    }

    public function useStandardTableLayout(): void
    {
        $this->applyTableState(new TableStateData);
        $this->activeTableLayoutId = null;
        $this->appliedTableLayoutHash = null;
    }

    public function saveTableLayoutAsAction(): Action
    {
        return Action::make('saveTableLayoutAs')
            ->label(__('filament/panel.tables.layouts.save_as'))
            ->modalHeading(__('filament/panel.tables.layouts.save_as'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.save'))
            ->schema([$this->tableLayoutNameInput()])
            ->action(function (array $data, Schema $schema): void {
                $layout = $this->withLayoutNameErrors($schema, fn (): TableLayout => resolve(CreateTableLayout::class)->execute(
                    $this->tableLayoutUser(),
                    $this->tableLayoutWorkspace(),
                    $this->tableEntity(),
                    $data['name'],
                    $this->currentTableState(),
                ));

                $this->activeTableLayoutId = $layout->getKey();
                $this->appliedTableLayoutHash = $this->currentTableState()->withoutWidths()->hash();
                $this->visibleTableLayouts = null;
            });
    }

    public function renameTableLayoutAction(): Action
    {
        return Action::make('renameTableLayout')
            ->label(__('filament/panel.tables.layouts.rename'))
            ->modalHeading(__('filament/panel.tables.layouts.rename'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.save'))
            ->visible(fn (): bool => $this->canUpdateActiveTableLayout())
            ->fillForm(fn (): array => ['name' => $this->getActiveTableLayout()?->name])
            ->schema([$this->tableLayoutNameInput()])
            ->action(function (array $data, Schema $schema): void {
                $layout = $this->getActiveTableLayout() ?? abort(404);

                $this->withLayoutNameErrors($schema, fn (): TableLayout => resolve(UpdateTableLayout::class)->execute(
                    $this->tableLayoutUser(),
                    $layout,
                    ['name' => $data['name']],
                ));

                $this->visibleTableLayouts = null;
            });
    }

    public function shareTableLayoutAction(): Action
    {
        return Action::make('shareTableLayout')
            ->label(__('filament/panel.tables.layouts.share'))
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-user-group')
            ->modalHeading(fn (): string => __('filament/panel.tables.layouts.share_heading', ['name' => $this->getActiveTableLayout()?->name]))
            ->modalDescription(fn (): string => __('filament/panel.tables.layouts.share_description', ['workspace' => $this->tableLayoutWorkspace()->name]))
            ->modalSubmitActionLabel(__('filament/panel.tables.layouts.share_confirm'))
            ->visible(fn (): bool => $this->canUpdateActiveTableLayout() && ! $this->getActiveTableLayout()?->is_shared)
            ->action(fn () => $this->setTableLayoutSharing(true));
    }

    public function deleteTableLayoutAction(): Action
    {
        return Action::make('deleteTableLayout')
            ->label(__('filament/panel.tables.layouts.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('filament/panel.tables.layouts.delete_heading', ['name' => $this->getActiveTableLayout()?->name]))
            ->modalDescription(__('filament/panel.tables.layouts.delete_description'))
            ->visible(fn (): bool => $this->canUpdateActiveTableLayout())
            ->action(function (): void {
                $layout = $this->getActiveTableLayout() ?? abort(404);

                resolve(DeleteTableLayout::class)->execute($this->tableLayoutUser(), $layout);

                $this->activeTableLayoutId = null;
                $this->appliedTableLayoutHash = null;
                $this->defaultTableLayoutId = $this->defaultTableLayoutId === $layout->getKey() ? null : $this->defaultTableLayoutId;
                $this->visibleTableLayouts = null;
            });
    }

    private function setTableLayoutSharing(bool $isShared): void
    {
        $layout = $this->getActiveTableLayout() ?? abort(404);

        $layout = resolve(UpdateTableLayout::class)->execute($this->tableLayoutUser(), $layout, ['is_shared' => $isShared]);
        $this->visibleTableLayouts = null;

        Notification::make()->success()->title(__($isShared ? 'filament/panel.tables.layouts.shared' : 'filament/panel.tables.layouts.unshared', ['name' => $layout->name]))->send();
    }

    private function tableLayoutNameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('filament/panel.tables.layouts.name'))
            ->required()
            ->maxLength(255)
            ->autofocus();
    }

    /**
     * @param  Closure(): TableLayout  $write
     */
    private function withLayoutNameErrors(Schema $schema, Closure $write): TableLayout
    {
        try {
            return $write();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                "{$schema->getStatePath()}.name" => $exception->errors()['name'] ?? [],
            ]);
        }
    }

    private function tableLayoutUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function tableLayoutWorkspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
