@php
    $layouts = $this->getVisibleTableLayouts();
    $active = $this->getActiveTableLayout();
    $isModified = $this->isTableLayoutModified();
    $canUpdate = $this->canUpdateActiveTableLayout();
    $userId = auth()->id();
    $own = $layouts->where('user_id', $userId);
    $shared = $layouts->where('user_id', '!=', $userId);
@endphp

<div class="fi-ta-layouts" wire:key="{{ $this->getId() }}.table.layouts">
    <x-filament::dropdown placement="bottom-start" width="xs" max-height="28rem">
        <x-slot name="trigger">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-queue-list" class="fi-ta-layouts-trigger">
                {{ $active?->name ?? __('filament/panel.tables.layouts.label') }}

                @if ($isModified)
                    <span class="font-normal text-gray-500 dark:text-gray-400">{{ __('filament/panel.tables.layouts.modified') }}</span>
                @endif

                <x-filament::icon icon="heroicon-m-chevron-down" class="fi-view-switcher-chevron" />
            </x-filament::button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :icon="$active ? 'heroicon-o-table-cells' : 'heroicon-m-check'"
                :color="$active ? 'gray' : 'primary'"
                wire:click="useStandardTableLayout"
            >
                {{ __('filament/panel.tables.layouts.standard') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        @foreach ([__('filament/panel.tables.layouts.mine') => $own, __('filament/panel.tables.layouts.shared_with_workspace') => $shared] as $heading => $group)
            @if ($group->isNotEmpty())
                <x-filament::dropdown.list>
                    <p class="px-2 pt-1 pb-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>

                    @foreach ($group as $layout)
                        <x-filament::dropdown.list.item
                            :icon="match (true) {
                                $layout->is($active) => 'heroicon-m-check',
                                $layout->user_id !== $userId => 'heroicon-o-user-group',
                                default => 'heroicon-o-bookmark',
                            }"
                            :color="$layout->is($active) ? 'primary' : 'gray'"
                            wire:click="applySavedLayout('{{ $layout->getKey() }}')"
                        >
                            <span class="flex items-center justify-between gap-3">
                                <span class="truncate">{{ $layout->name }}</span>

                                @if ($layout->getKey() === $this->defaultTableLayoutId)
                                    <x-filament::icon icon="heroicon-s-star" :size="\Filament\Support\Enums\IconSize::ExtraSmall" class="shrink-0 text-warning-500" :label="__('filament/panel.tables.layouts.default')" />
                                @elseif ($layout->user_id !== $userId)
                                    <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $layout->user?->name }}</span>
                                @endif
                            </span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
        @endforeach

        @if ($active)
            <x-filament::dropdown.list>
                <p class="truncate px-2 pt-1 pb-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $active->name }}</p>

                @if ($canUpdate)
                    <x-filament::dropdown.list.item icon="heroicon-o-pencil-square" wire:click="mountAction('renameTableLayout')">
                        {{ __('filament/panel.tables.layouts.rename') }}
                    </x-filament::dropdown.list.item>

                    @if ($active->is_shared)
                        <x-filament::dropdown.list.item icon="heroicon-o-user-group" wire:click="stopSharingTableLayout">
                            {{ __('filament/panel.tables.layouts.unshare') }}
                        </x-filament::dropdown.list.item>
                    @else
                        <x-filament::dropdown.list.item icon="heroicon-o-user-group" wire:click="mountAction('shareTableLayout')">
                            {{ __('filament/panel.tables.layouts.share') }}
                        </x-filament::dropdown.list.item>
                    @endif
                @endif

                <x-filament::dropdown.list.item icon="heroicon-o-star" wire:click="toggleDefaultTableLayout">
                    {{ $active->getKey() === $this->defaultTableLayoutId ? __('filament/panel.tables.layouts.unset_default') : __('filament/panel.tables.layouts.set_default') }}
                </x-filament::dropdown.list.item>

                @if ($canUpdate)
                    <x-filament::dropdown.list.item icon="heroicon-o-trash" color="danger" wire:click="mountAction('deleteTableLayout')">
                        {{ __('filament/panel.tables.layouts.delete') }}
                    </x-filament::dropdown.list.item>
                @endif
            </x-filament::dropdown.list>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item icon="heroicon-o-plus" wire:click="mountAction('saveTableLayoutAs')">
                {{ __('filament/panel.tables.layouts.save_as') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    @if ($isModified)
        <x-filament::button
            color="gray"
            size="sm"
            icon="heroicon-o-arrow-uturn-left"
            label-sr-only
            :tooltip="__('filament/panel.tables.layouts.revert')"
            wire:click="revertTableLayout"
        >
            {{ __('filament/panel.tables.layouts.revert') }}
        </x-filament::button>

        @if ($canUpdate)
            <x-filament::button
                color="gray"
                size="sm"
                icon="heroicon-o-check"
                label-sr-only
                :tooltip="__('filament/panel.tables.layouts.save')"
                class="fi-ta-layouts-save"
                wire:click="saveTableLayout"
            >
                {{ __('filament/panel.tables.layouts.save') }}
            </x-filament::button>
        @endif
    @endif
</div>
