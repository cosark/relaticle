<?php

declare(strict_types=1);

use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\ResizableColumns;
use App\Models\Company;
use App\Models\People;
use App\Models\User;

mutates(HasResizableColumns::class, ResizableColumns::class);

function headerSelector(string $column): string
{
    return "th[x-data^=\"resizableColumn('{$column}'\"]";
}

function headerWidth(string $column): string
{
    return sprintf('(() => Math.round(document.querySelector(`%s`).getBoundingClientRect().width))()', headerSelector($column));
}

function dragColumnEdge(string $column, int $by): string
{
    return sprintf(<<<'JS'
        (() => {
            const handle = document.querySelector(`%s .column-resize-handle-bar`);
            const box = handle.getBoundingClientRect();
            const x = box.left + box.width / 2;
            const y = box.top + box.height / 2;
            handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0, clientX: x, clientY: y }));
            document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: x + %d, clientY: y }));
            document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, clientX: x + %d, clientY: y }));
            return true;
        })();
    JS, headerSelector($column), $by, $by);
}

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
    People::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('resizes a column by dragging its edge and keeps the width after a reload', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(dragColumnEdge('created_at', 120));
    $page->assertScript(headerWidth('created_at'), $before + 120)
        ->wait(1)
        ->refresh()
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar')
        ->assertScript(headerWidth('created_at'), $before + 120)
        ->assertNoJavaScriptErrors();
});

it('fits a column to its content on double-click', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->assertVisible(headerSelector('created_at').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('created_at'));

    $page->script(sprintf(
        'document.querySelector(`%s .column-resize-handle-bar`).dispatchEvent(new MouseEvent("dblclick", { bubbles: true }))',
        headerSelector('created_at'),
    ));

    $after = $page->script(headerWidth('created_at'));

    expect($after)->not->toBe($before)->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(1000);
    $page->assertNoJavaScriptErrors();
});

it('keeps resizing after navigating to another table and after sorting', function (): void {
    $savedWidth = sprintf('(() => document.querySelector(`%s`).dataset.resizableWidth)()', headerSelector('name'));
    $oneHandlePerHeader = '(() => [...document.querySelectorAll(`th[x-data^="resizableColumn"]`)].every((th) => th.querySelectorAll(".column-resize-handle-bar").length === 1))()';

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click("a.fi-sidebar-item-btn[href$='/app/{$this->workspace->slug}/people']")
        ->assertPathIs("/app/{$this->workspace->slug}/people")
        ->assertVisible(headerSelector('name').' .column-resize-handle-bar');

    $before = $page->script(headerWidth('name'));

    $page->script(dragColumnEdge('name', 120));
    $page->wait(1);

    $page->script(sprintf('document.querySelector(`%s .fi-ta-header-cell-sort-btn`).click()', headerSelector('name')));

    $page->wait(1)
        ->assertScript($savedWidth, (string) ($before + 120))
        ->assertScript($oneHandlePerHeader, true)
        ->assertNoJavaScriptErrors();
});
