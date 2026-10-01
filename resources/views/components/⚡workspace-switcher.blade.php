<?php

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function current(): ?Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    #[Computed]
    public function role(): ?WorkspaceRole
    {
        return $this->current ? Auth::user()->roleIn($this->current) : null;
    }

    #[Computed]
    public function workspaces()
    {
        return Auth::user()->workspaces()->orderBy('name')->get();
    }

    public function switchTo(int $id): void
    {
        $workspace = Auth::user()->workspaces()->findOrFail($id);

        Auth::user()->switchWorkspace($workspace);

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    @if ($this->current)
        <flux:dropdown position="bottom" align="start">
            <button type="button"
                class="flex w-full items-center gap-3 rounded-2xl bg-white/70 px-3 py-2 text-start dark:bg-white/10">
                <span class="grid size-8 shrink-0 place-items-center rounded-xl bg-brand text-sm font-semibold text-ink">
                    {{ str($this->current->name)->substr(0, 1)->upper() }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">{{ $this->current->name }}</span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $this->role?->label() }}</span>
                </span>
                <flux:icon name="chevron-up-down" class="size-4 text-zinc-400" />
            </button>

            <flux:menu>
                @foreach ($this->workspaces as $workspace)
                    <flux:menu.item wire:key="ws-{{ $workspace->id }}" wire:click="switchTo({{ $workspace->id }})"
                        :icon="$workspace->id === $this->current->id ? 'check' : null">
                        {{ $workspace->name }}
                    </flux:menu.item>
                @endforeach

                <flux:menu.separator />

                <flux:menu.item :href="route('workspaces.create')" icon="plus" wire:navigate>
                    {{ __('New workspace') }}
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>