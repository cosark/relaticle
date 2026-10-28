<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoteResource\Pages;

use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Concerns\HasNoteHeaderActions;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\NoteResource;
use App\Filament\Tables\Concerns\HasResizableColumns;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\On;

final class ManageNotes extends ManageRecords
{
    use HasCustomFieldColumns;
    use HasNoteHeaderActions;
    use HasResizableColumns;
    use HasViewSwitcher;

    protected static string $resource = NoteResource::class;

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
