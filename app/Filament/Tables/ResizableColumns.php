<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Support\Str;

final class ResizableColumns
{
    public const int MIN_WIDTH = 40;

    public const int MAX_WIDTH = 1000;

    private const int DEFAULT_COLUMN_WIDTH = 180;

    // Timestamps and ids look broken when stretched, so they keep an exact width.
    private const array FIXED_COLUMN_PATTERNS = [
        '/^id$/',
        '/_at$/',
        '/^user\.name$/',
    ];

    public static function apply(Table $table): Table
    {
        $livewire = $table->getLivewire();

        if (! method_exists($livewire, 'getResizableColumnWidths')) {
            return $table;
        }

        $widths = $livewire->getResizableColumnWidths();

        foreach ($table->getColumns() as $name => $column) {
            self::stamp($column, $name, (int) ($widths[$name]['width'] ?? $column->getWidth() ?? self::DEFAULT_COLUMN_WIDTH));
        }

        return $table;
    }

    private static function stamp(Column $column, string $name, int $width): void
    {
        $min = self::MIN_WIDTH;
        $max = self::MAX_WIDTH;
        $columnId = (string) Str::of($name)->camel()->kebab();
        $type = self::isElastic($name, $column) ? 'elastic' : 'fixed';
        $style = $type === 'elastic'
            ? "min-width: {$width}px"
            : "min-width: {$width}px; width: {$width}px; max-width: {$width}px";
        $attributes = [
            'data-resizable-width' => (string) $width,
            'data-resizable-type' => $type,
            'data-resizable-column-id' => $columnId,
        ];

        $column->extraHeaderAttributes([
            ...$attributes,
            'x-data' => "resizableColumn('{$name}', '{$columnId}', {$min}, {$max})",
            'class' => 'fi-ta-resizable-header',
            'style' => $style,
        ]);

        $column->extraCellAttributes([...$attributes, 'style' => "{$style}; overflow: hidden"]);
    }

    private static function isElastic(string $name, Column $column): bool
    {
        if ($column->getWidth() !== null) {
            return false;
        }

        if ($column->canGrow(default: false)) {
            return true;
        }

        return array_all(self::FIXED_COLUMN_PATTERNS, fn (string $pattern): bool => preg_match($pattern, $name) !== 1);
    }
}
