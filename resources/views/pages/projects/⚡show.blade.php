<?php

use App\Enums\ProjectStatus;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project')]
    class extends Component {
    public string $slug;

    public string $name = '';
    public string $description = '';
    public string $color = 'lavender';
    public string $status = 'planning';
    public ?string $due_date = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        Gate::authorize('view', $this->project);

        $this->fillForm();
    }

    // Scoped to the current workspace: a slug alone is never trusted.
    #[Computed]
    public function project(): Project
    {
        return Auth::user()->currentWorkspace->projects()->where('slug', $this->slug)->firstOrFail();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('update', $this->project);
    }

    protected function fillForm(): void
    {
        $this->name = $this->project->name;
        $this->description = $this->project->description ?? '';
        $this->color = $this->project->color;
        $this->status = $this->project->status->value;
        $this->due_date = $this->project->due_date?->format('Y-m-d');
    }

    public function update(): void
    {
        Gate::authorize('update', $this->project);

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', Rule::in(Project::COLORS)],
            'status' => ['required', Rule::enum(ProjectStatus::class)],
            'due_date' => ['nullable', 'date'],
        ]);

        $validated['due_date'] = $validated['due_date'] ?: null;
        $validated['description'] = $validated['description'] ?: null;

        $this->project->update($validated);
        unset($this->project);

        Flux::modal('edit-project')->close();
        Flux::toast(variant: 'success', text: 'Project updated.');
    }

    public function archive(): void
    {
        Gate::authorize('delete', $this->project);

        $this->project->delete();

        $this->redirectRoute('projects.index', navigate: true);
    }
}; ?>

@php
    $p = $this->project;
    $daysLeft = $p->due_date ? (int) now()->startOfDay()->diffInDays($p->due_date->startOfDay(), false) : null;
    $selectClasses = 'rounded-xl border border-zinc-300/70 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10';
@endphp

<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('projects.index') }}" wire:navigate
                class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
            <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $p->name }}</h1>
        </div>

        @if ($this->canManage)
            <div class="flex gap-2">
                <flux:modal.trigger name="edit-project">
                    <flux:button icon="pencil-square" class="!rounded-full">Edit</flux:button>
                </flux:modal.trigger>
                <flux:modal.trigger name="archive-project">
                    <flux:button icon="archive-box" variant="ghost" class="!rounded-full">Archive</flux:button>
                </flux:modal.trigger>
            </div>
        @endif
    </div>

    <x-project-tabs :project="$p" active="overview" />

    <div class="grid gap-4 lg:grid-cols-12">
        <x-card :tone="$p->color" class="min-h-56 lg:col-span-8">
            <div class="flex items-start justify-between">
                <h2 class="text-lg font-medium">About</h2>
                <span class="rounded-full bg-white/60 px-2.5 py-0.5 text-xs">{{ $p->status->label() }}</span>
            </div>
            <p class="mt-6 max-w-2xl text-lg font-light leading-relaxed">
                {{ $p->description ?: 'No description yet.' }}
            </p>
        </x-card>

        <x-card tone="dark" class="flex flex-col justify-between lg:col-span-4">
            <h2 class="text-lg font-medium">Deadline</h2>
            <div class="my-6">
                @if ($daysLeft === null)
                    <div class="text-7xl font-light leading-none">—</div>
                    <p class="mt-2 text-sm text-white/60">No due date set</p>
                @elseif ($daysLeft < 0)
                    <div class="text-7xl font-light leading-none">{{ abs($daysLeft) }}</div>
                    <p class="mt-2 text-sm text-white/60">days overdue</p>
                @else
                    <div class="text-7xl font-light leading-none">{{ $daysLeft }}</div>
                    <p class="mt-2 text-sm text-white/60">days left · {{ $p->due_date->format('M j, Y') }}</p>
                @endif
            </div>
        </x-card>

        <x-card class="lg:col-span-12">
            <dl class="grid gap-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Status</dt>
                    <dd class="mt-1 text-lg font-light">{{ $p->status->label() }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Due date</dt>
                    <dd class="mt-1 text-lg font-light">{{ $p->due_date?->format('M j, Y') ?? 'Not set' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Created by</dt>
                    <dd class="mt-1 text-lg font-light">{{ $p->creator?->name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500 dark:text-zinc-400">Created</dt>
                    <dd class="mt-1 text-lg font-light">{{ $p->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>
        </x-card>
    </div>

    <flux:modal name="edit-project" class="w-full max-w-lg">
        <form wire:submit="update" class="space-y-6">
            <flux:heading size="lg">Edit project</flux:heading>

            <flux:input wire:model="name" label="Name" required />
            <flux:textarea wire:model="description" label="Description" rows="3" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Status</flux:label>
                    <select wire:model="status" class="{{ $selectClasses }}">
                        @foreach (\App\Enums\ProjectStatus::cases() as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <flux:error name="status" />
                </flux:field>

                <flux:input wire:model="due_date" type="date" label="Due date" />
            </div>

            <flux:field>
                <flux:label>Color</flux:label>
                <x-color-swatches wire:model="color" />
                <flux:error name="color" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save changes</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="archive-project" class="w-full max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Archive this project?</flux:heading>
                <flux:subheading>It moves to the recycle bin and disappears from your projects list.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="archive">Archive project</flux:button>
            </div>
        </div>
    </flux:modal>
</section>