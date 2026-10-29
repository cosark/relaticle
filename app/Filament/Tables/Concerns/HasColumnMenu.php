<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

trait HasColumnMenu
{
    public function moveTableColumn(string $columnName, ?string $beforeColumnName): void
    {
        $names = array_column($this->movableTableColumns(), 'name');

        if (! in_array($columnName, $names, true) || $columnName === $beforeColumnName) {
            return;
        }

        if ($beforeColumnName !== null && $beforeColumnName !== $this->tableEntity()->titleColumn() && ! in_array($beforeColumnName, $names, true)) {
            return;
        }

        $columns = array_values(array_filter($this->tableColumns, fn (array $item): bool => $item['name'] !== $columnName));
        $moved = array_values(array_filter($this->tableColumns, fn (array $item): bool => $item['name'] === $columnName));
        $position = $beforeColumnName === null ? count($columns) : array_search($beforeColumnName, array_column($columns, 'name'), true);

        array_splice($columns, (int) $position, 0, $moved);

        $this->applyTableColumnManager($columns, wasReordered: true);
    }

    public function hideTableColumn(string $columnName): void
    {
        if (! $this->getTable()->getColumn($columnName)?->isToggleable()) {
            return;
        }

        $this->applyTableColumnManager(array_map(
            fn (array $item): array => $item['name'] === $columnName ? [...$item, 'isToggled' => false] : $item,
            $this->tableColumns,
        ), wasReordered: true);
    }

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>|null  $state
     */
    public function applyTableColumnManager(?array $state = null, bool $wasReordered = false): void
    {
        parent::applyTableColumnManager($state, $wasReordered);

        $columns = $this->withTitleColumnFirst($this->tableColumns);

        if ($columns !== $this->tableColumns) {
            parent::applyTableColumnManager($columns, wasReordered: true);
        }
    }

    /**
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function movableTableColumns(): array
    {
        $title = $this->tableEntity()->titleColumn();

        return array_filter($this->tableColumns, fn (array $item): bool => $item['type'] === self::TABLE_COLUMN_MANAGER_COLUMN_TYPE && $item['name'] !== $title);
    }

    /**
     * @param  array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>  $columns
     * @return array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool, columns?: array<int, array{type: string, name: string, label: string, isHidden: bool, isToggled: bool, isToggleable: bool, isToggledHiddenByDefault: ?bool}>}>
     */
    private function withTitleColumnFirst(array $columns): array
    {
        $title = $this->tableEntity()->titleColumn();

        usort($columns, fn (array $a, array $b): int => ($b['name'] === $title) <=> ($a['name'] === $title));

        return $columns;
    }
}
