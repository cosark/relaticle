<?php

declare(strict_types=1);

use App\Actions\TableLayouts\CreateTableLayout;
use App\Actions\TableLayouts\DeleteTableLayout;
use App\Actions\TableLayouts\EnsureTableLayoutNameIsFree;
use App\Actions\TableLayouts\SetDefaultTableLayout;
use App\Actions\TableLayouts\UpdateTableLayout;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use App\Policies\TableLayoutPolicy;
use Filament\Facades\Filament;

mutates(
    HasTableLayouts::class,
    PersistsTableState::class,
    TableLayoutPolicy::class,
    CreateTableLayout::class,
    UpdateTableLayout::class,
    DeleteTableLayout::class,
    SetDefaultTableLayout::class,
    EnsureTableLayoutNameIsFree::class,
);

dataset('layout list pages', [
    'companies' => [ListCompanies::class, CrmEntity::Company],
    'people' => [ListPeople::class, CrmEntity::People],
    'opportunities' => [ListOpportunities::class, CrmEntity::Opportunity],
    'tasks' => [ManageTasks::class, CrmEntity::Task],
    'notes' => [ManageNotes::class, CrmEntity::Note],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();
});

function tableLayoutMember(WorkspaceRole $role): User
{
    $member = User::factory()->create(['current_workspace_id' => test()->workspace->getKey()]);
    test()->workspace->users()->attach($member, ['role' => $role->value]);

    return $member;
}

function layoutFor(User $owner, array $attributes = []): TableLayout
{
    return TableLayout::factory()->for($owner)->for(test()->workspace)->create($attributes);
}

function hotLeads(): TableStateData
{
    return new TableStateData(
        columns: [['type' => 'column', 'name' => 'created_at', 'isToggled' => false]],
        sort: 'created_at:desc',
        filters: ['trashed' => ['value' => 0]],
        search: 'acme',
        perPage: 50,
    );
}

function preferenceRow(): ?TablePreference
{
    return TablePreference::query()->withoutGlobalScopes()->first();
}

it('applies columns, sort, filters, search and page size in one call', function (string $page, CrmEntity $entity): void {
    $layout = layoutFor($this->user, ['entity' => $entity, 'state' => hotLeads()]);

    $component = livewire($page)->call('applySavedLayout', $layout->getKey());

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('tableSearch'))->toBe('acme')
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(50)
        ->and($component->get('tableFilters.trashed.value'))->toEqual(0)
        ->and($component->instance()->getTableFiltersForm()->getRawState()['trashed']['value'])->toEqual(0)
        ->and($component->get('tableColumns.0.name'))->toBe('created_at')
        ->and($component->get('tableColumns.0.isToggled'))->toBeFalse()
        ->and($component->instance()->isTableLayoutModified())->toBeFalse()
        ->and(preferenceRow()->active_layout_id)->toBe($layout->getKey());
})->with('layout list pages');

it('falls back to the default page size when a layout holds one the table does not offer', function (): void {
    $layout = layoutFor($this->user, ['state' => new TableStateData(perPage: 200)]);

    expect((int) livewire(ListCompanies::class)->set('tableRecordsPerPage', 50)->call('applySavedLayout', $layout->getKey())->get('tableRecordsPerPage'))->toBe(25);
});

it('applies a sort through the grouping a layout names', function (): void {
    $layout = layoutFor($this->user, ['state' => new TableStateData(sort: 'name:asc', search: null)]);

    $component = livewire(ListCompanies::class)->set('tableSearch', 'acme')->set('tableSort', 'created_at:desc')->call('applySavedLayout', $layout->getKey());

    expect($component->get('tableSearch'))->toBeEmpty()
        ->and($component->get('tableSort'))->toBe('name:asc');
});

it('saves the current table as a new layout without widths and applies it', function (): void {
    $component = livewire(ListCompanies::class)
        ->set('tableSort', 'created_at:desc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->callAction('saveTableLayoutAs', ['name' => 'Hot leads'])
        ->assertHasNoActionErrors()
        ->assertNotified('"Hot leads" saved as a new layout');

    $layout = TableLayout::query()->withoutGlobalScopes()->sole();

    expect($layout->name)->toBe('Hot leads')
        ->and($layout->is_shared)->toBeFalse()
        ->and($layout->state->sort)->toBe('created_at:desc')
        ->and($layout->state->widths)->toBe([])
        ->and($component->get('activeTableLayoutId'))->toBe($layout->getKey());

    $component->assertSeeText('Hot leads')->assertDontSeeText('(modified)');
});

it('refuses a layout name the member already uses, ignoring case', function (): void {
    layoutFor($this->user, ['name' => 'Hot leads']);

    livewire(ListCompanies::class)
        ->callAction('saveTableLayoutAs', ['name' => 'hot LEADS '])
        ->assertHasActionErrors(['name']);

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets two members use the same layout name', function (): void {
    layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Hot leads', 'is_shared' => true]);

    livewire(ListCompanies::class)->callAction('saveTableLayoutAs', ['name' => 'Hot leads'])->assertHasNoActionErrors();

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('marks a changed layout as modified, reverts it and saves over it', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSort', 'name:asc');

    expect($component->instance()->isTableLayoutModified())->toBeTrue();
    $component->assertSeeText('(modified)')->assertSeeHtml('wire:click="revertTableLayout"');

    $component->call('revertTableLayout');
    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();

    $component->set('tableSort', 'name:asc')->call('saveTableLayout');
    expect($layout->refresh()->state->sort)->toBe('name:asc')
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();
});

it('ignores widths when telling whether a layout was changed', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('updateResizableColumnWidth', 'created_at', '300');

    expect($component->instance()->isTableLayoutModified())->toBeFalse();
});

it('brings back the last state over the default layout, and the modified mark with it', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout')->set('tableSort', 'name:asc');

    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->get('activeTableLayoutId'))->toBe($layout->getKey())
        ->and($revisit->instance()->isTableLayoutModified())->toBeTrue();
});

it('opens on the default layout when the member has no saved state, and writes nothing', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['default_layout_id' => $layout->getKey()]);
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = livewire(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeTableLayoutId'))->toBe($layout->getKey())
        ->and($component->instance()->isTableLayoutModified())->toBeFalse()
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('switches back to the standard layout from a saved one', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('useStandardTableLayout');

    expect($component->get('tableSort'))->toBeNull()
        ->and($component->get('tableSearch'))->toBeEmpty()
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(25)
        ->and($component->get('activeTableLayoutId'))->toBeNull()
        ->and(collect($component->get('tableColumns'))->firstWhere('name', 'created_at')['isToggled'])->toBeTrue();

    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBeNull();
});

it('offers the standard layout and nothing to manage when no layout is saved', function (): void {
    livewire(ListCompanies::class)
        ->assertSeeText('Standard layout')
        ->assertActionHidden('renameTableLayout')
        ->assertActionHidden('shareTableLayout')
        ->assertActionHidden('deleteTableLayout');
});

it('shares a layout only after the member confirms', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Hot leads']);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->mountAction('shareTableLayout');

    expect($layout->refresh()->is_shared)->toBeFalse();
    $component->assertActionMounted('shareTableLayout');

    $component->callMountedAction();
    expect($layout->refresh()->is_shared)->toBeTrue();

    $component->call('stopSharingTableLayout');
    expect($layout->refresh()->is_shared)->toBeFalse();
});

it('gives a member the new version of a shared layout they had not changed', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey());

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->instance()->isTableLayoutModified())->toBeFalse();
});

it('keeps a member\'s changed copy when the shared layout changes under it', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSearch', 'mine');

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSearch'))->toBe('mine')
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->instance()->isTableLayoutModified())->toBeTrue();
});

it('clears other members\' pointers when a layout stops being shared, and keeps their table', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout');

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['is_shared' => false]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect(preferenceRow()->only('active_layout_id', 'default_layout_id', 'applied_layout_hash'))->toBe(['active_layout_id' => null, 'default_layout_id' => null, 'applied_layout_hash' => null])
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->get('activeTableLayoutId'))->toBeNull();
});

it('keeps the table and clears the pointers when a layout is deleted', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout')
        ->callAction('deleteTableLayout')
        ->assertSet('activeTableLayoutId', null)
        ->assertSet('defaultTableLayoutId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(preferenceRow()->active_layout_id)->toBeNull();
});

it('lists own layouts and layouts shared with the workspace, never someone else\'s private one', function (): void {
    $other = tableLayoutMember(WorkspaceRole::Member);
    $mine = layoutFor($this->user, ['name' => 'Mine']);
    layoutFor($other, ['name' => 'Renewals this month', 'is_shared' => true]);
    $private = layoutFor($other, ['name' => 'Private plan']);

    livewire(ListCompanies::class)
        ->assertSeeText(['Mine', 'Renewals this month', $other->name])
        ->assertDontSeeText('Private plan')
        ->assertSeeHtml("applySavedLayout('{$mine->getKey()}')")
        ->call('applySavedLayout', $private->getKey())
        ->assertNotFound();
});

it('keeps layouts apart per table and per workspace', function (): void {
    layoutFor($this->user, ['name' => 'People layout', 'entity' => CrmEntity::People]);
    TableLayout::factory()->for($this->user)->for(User::factory()->withWorkspace()->create()->currentWorkspace)->create(['name' => 'Elsewhere']);

    livewire(ListCompanies::class)->assertDontSeeText(['People layout', 'Elsewhere']);
});

it('lets any member, a viewer too, save and share a layout', function (): void {
    $viewer = tableLayoutMember(WorkspaceRole::Viewer);
    $this->actingAs($viewer);

    livewire(ListCompanies::class)
        ->callAction('saveTableLayoutAs', ['name' => 'Viewer layout'])
        ->callAction('shareTableLayout');

    expect(TableLayout::query()->withoutGlobalScopes()->sole()->is_shared)->toBeTrue();
});

it('lets a member apply but not change someone else\'s shared layout', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Member));

    livewire(ListCompanies::class)
        ->call('applySavedLayout', $layout->getKey())
        ->assertActionHidden('renameTableLayout')
        ->assertActionHidden('deleteTableLayout')
        ->call('saveTableLayout')
        ->assertForbidden();
});

it('lets an admin rename and delete someone else\'s shared layout, but not see a private one', function (): void {
    $admin = tableLayoutMember(WorkspaceRole::Admin);
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $shared = layoutFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $private = layoutFor($owner, ['name' => 'Private']);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->call('applySavedLayout', $shared->getKey())
        ->callAction('renameTableLayout', ['name' => 'Team pipeline'])
        ->assertHasNoActionErrors();

    expect($shared->refresh()->name)->toBe('Team pipeline')
        ->and($admin->can('update', $private))->toBeFalse()
        ->and($admin->can('delete', $shared))->toBeTrue();

    livewire(ListCompanies::class)->call('applySavedLayout', $shared->getKey())->callAction('deleteTableLayout');

    expect(TableLayout::query()->withoutGlobalScopes()->whereKey($shared->getKey())->exists())->toBeFalse();
});

it('lets an admin who stops sharing someone else\'s layout keep working on the table', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)
        ->call('applySavedLayout', $layout->getKey())
        ->call('stopSharingTableLayout')
        ->assertSet('activeTableLayoutId', null)
        ->set('tableSort', 'name:asc')
        ->call('revertTableLayout')
        ->assertOk()
        ->assertDontSeeText('(modified)');

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and($layout->refresh()->is_shared)->toBeFalse();
});

it('lets the owner keep managing a layout after an admin edits it', function (): void {
    $layout = layoutFor($this->user, ['is_shared' => true]);

    expect($this->user->can('update', $layout))->toBeTrue()
        ->and(tableLayoutMember(WorkspaceRole::Viewer)->can('update', $layout))->toBeFalse()
        ->and(User::factory()->create()->can('view', $layout))->toBeFalse();
});
