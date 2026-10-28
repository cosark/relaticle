<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Filament\Tables\ResizableColumns;
use Livewire\Attributes\Renderless;

trait HasResizableColumns
{
    /**
     * @return array<string, array{width: string}>
     */
    public function getResizableColumnWidths(): array
    {
        return session($this->resizableColumnsSessionKey(), []);
    }

    #[Renderless]
    public function updateResizableColumnWidth(string $columnName, string $newWidth): void
    {
        $width = (int) $newWidth;

        if ($width <= 0 || $this->getTable()->getColumn($columnName) === null) {
            return;
        }

        $width = max(ResizableColumns::MIN_WIDTH, min(ResizableColumns::MAX_WIDTH, $width));

        session()->put($this->resizableColumnsSessionKey(), [
            ...$this->getResizableColumnWidths(),
            $columnName => ['width' => (string) $width],
        ]);
    }

    private function resizableColumnsSessionKey(): string
    {
        return 'tables.resizable.'.hash('xxh128', static::class);
    }
}
