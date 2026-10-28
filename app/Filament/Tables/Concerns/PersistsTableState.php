<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\TableLayouts\SaveTablePreference;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\Filament\AppPanelProvider;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;
use LogicException;

trait PersistsTableState
{
    #[Locked]
    public ?string $tableStateHash = null;

    private ?TableStateData $storedTableState = null;

    public function mountInteractsWithTable(): void
    {
        parent::mountInteractsWithTable();

        $stored = $this->storedTableState();

        $this->tableSort = blank($this->tableSort) ? $stored->sort : $this->tableSort;
        $this->tableFilters = blank($this->tableFilters) ? ($stored->filters ?: null) : $this->tableFilters;
        $this->tableSearch = blank($this->tableSearch) ? $stored->search : $this->tableSearch;
        $this->tableColumnSearches = blank($this->tableColumnSearches) ? $stored->columnSearches : $this->tableColumnSearches;
        $this->tableGrouping = blank($this->tableGrouping) ? $stored->grouping : $this->tableGrouping;
        $this->tableRecordsPerPage = blank($this->tableRecordsPerPage) ? $stored->perPage : $this->tableRecordsPerPage;
        $this->tableColumnWidths = $stored->widths;
    }

    public function bootedInteractsWithTable(): void
    {
        parent::bootedInteractsWithTable();

        $table = $this->getTable();

        $this->tableColumnWidths = array_intersect_key($this->tableColumnWidths, $table->getColumns());

        if (filled($this->tableSort) && ! array_key_exists(explode(':', $this->tableSort)[0], $table->getColumns())) {
            $this->tableSort = null;
        }

        if ($table->isPaginated() && ! in_array($this->tableRecordsPerPage, AppPanelProvider::pageSizeOptions($table))) {
            $this->tableRecordsPerPage = $this->getDefaultTableRecordsPerPageSelectOption();
        }
    }

    public function dehydratePersistsTableState(): void
    {
        $state = $this->currentTableState();
        $hash = $state->hash();

        if ($this->tableStateHash !== null && $this->tableStateHash !== $hash) {
            /** @var User $user */
            $user = auth()->user();
            /** @var Workspace $workspace */
            $workspace = Filament::getTenant();

            resolve(SaveTablePreference::class)->execute($user, $workspace, $this->tableEntity(), $state);
        }

        $this->tableStateHash = $hash;
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    protected function loadTableColumnsFromSession(): array
    {
        $defaults = $this->getDefaultTableColumnState();
        $stored = $this->storedTableState()->columns;

        if ($stored === []) {
            return $defaults;
        }

        $byName = array_column($defaults, null, 'name');
        $columns = [];

        foreach ($stored as $item) {
            $name = $item['name'] ?? null;

            if ($name !== null && isset($byName[$name])) {
                $columns[] = [...$byName[$name], 'isToggled' => $item['isToggled'] ?? true];
            }
        }

        return $columns;
    }

    private function tableEntity(): CrmEntity
    {
        $model = $this->getModel();

        return CrmEntity::tryFromModel(new $model)
            ?? throw new LogicException("{$model} is not a CRM entity, so its table state cannot be remembered.");
    }

    private function storedTableState(): TableStateData
    {
        return $this->storedTableState ??= TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', Filament::getTenant()->getKey())
            ->whereBelongsTo(auth()->user())
            ->where('entity', $this->tableEntity())
            ->first()
            ->state ?? new TableStateData;
    }

    private function currentTableState(): TableStateData
    {
        $columns = [];

        foreach ($this->tableColumns as $item) {
            $columns[] = ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']];
        }

        return new TableStateData(
            columns: $columns,
            sort: filled($this->tableSort) ? $this->tableSort : null,
            filters: $this->tableFilters ?? [],
            search: filled($this->tableSearch) ? $this->tableSearch : null,
            columnSearches: array_filter($this->tableColumnSearches, filled(...)),
            grouping: filled($this->tableGrouping) ? $this->tableGrouping : null,
            perPage: $this->tableRecordsPerPage,
            widths: array_intersect_key($this->tableColumnWidths, $this->getTable()->getColumns()),
        );
    }
}
