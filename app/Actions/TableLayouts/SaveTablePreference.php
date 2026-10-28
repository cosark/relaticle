<?php

declare(strict_types=1);

namespace App\Actions\TableLayouts;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\TablePreference;
use App\Models\User;
use App\Models\Workspace;

final readonly class SaveTablePreference
{
    public function execute(User $user, Workspace $workspace, CrmEntity $entity, TableStateData $state): TablePreference
    {
        abort_unless($user->belongsToWorkspaceId($workspace->getKey()), 403);

        return TablePreference::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->updateOrCreate(
                ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'entity' => $entity],
                ['state' => $state],
            );
    }
}
