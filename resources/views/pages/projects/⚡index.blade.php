<?php

use App\Models\Project;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Projects')]
    class extends Component {
    public string $name = '';
    public string $description = '';
    public string $color = 'lavender';
    public ?string $due_date = null;

    #[Computed]
    public function projects()
    {
        return Auth::user()->currentWorkspace->projects()->latest()->get();
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Auth::user()->can('create', Project::class);
    }

    public function create(): void
    {
        Gate::authorize('create', Project::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', Rule::in(Project::COLORS)],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $validated['due_date'] = $validated['due_date'] ?: null;
        $validated['description'] = $validated['description'] ?: null;

        Auth::user()->currentWorkspace->projects()->create([
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        $this->reset('name', 'description', 'due_date', 'color');
        unset($this->projects);

        Flux::modal('create-project')->close();
        Flux::toast(variant: 'success', text: __('Project created.'));
    }
}; ?>


<section class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ auth()->user()->currentWorkspace->name }}</p>
            <h1 class="text-4xl font-light tracking-tight sm:text-5xl">Projects</h1>
        </div>

        @if ($this->canCreate)
            <flux:modal.trigger name="create-project">
                <flux:button variant="primary" icon="plus" class="!rounded-full">New project</flux:button>
            </flux:modal.trigger>
        @endif
    </div>

    @if ($this->projects->isEmpty())
        <x-card class="grid place-items-center gap-2 py-20 text-center">
            <h2 class="text-2xl font-light">No projects yet</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->canCreate ? 'Create your first project to get started.' : 'Nothing here yet. Ask an admin to create a project.' }}
            </p>
        </x-card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->projects as $project)
                <x-card :tone="$project->color" wire:key="project-{{ $project->id }}"
                    class="relative flex min-h-52 flex-col justify-between transition hover:-translate-y-0.5">
                    <a href="{{ route('projects.show', $project->slug) }}" wire:navigate class="absolute inset-0 rounded-card"
                        aria-label="{{ $project->name }}"></a>
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-xl font-medium">{{ $project->name }}</h3>
                        <span
                            class="shrink-0 rounded-full bg-white/60 px-2.5 py-0.5 text-xs">{{ $project->status->label() }}</span>
                    </div>

                    <div>
                        @if ($project->description)
                            <p class="mb-4 line-clamp-2 text-sm text-ink/70">{{ $project->description }}</p>
                        @endif
                        <p class="text-sm">
                            {{ $project->due_date ? 'Due ' . $project->due_date->format('M j, Y') : 'No deadline' }}
                        </p>
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif

    <flux:modal name="create-project" class="w-full max-w-lg">
        <form wire:submit="create" class="space-y-6">
            <div>
                <flux:heading size="lg">New project</flux:heading>
                <flux:subheading>Projects hold your tasks, files and change requests.</flux:subheading>
            </div>

            <flux:input wire:model="name" label="Name" placeholder="DevFlow Website" required />
            <flux:textarea wire:model="description" label="Description" rows="3" />
            <flux:input wire:model="due_date" type="date" label="Due date" />

            <flux:field>
                <flux:label>Color</flux:label>
                <x-color-swatches wire:model="color" />
                <flux:error name="color" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create project</flux:button>
            </div>
        </form>
    </flux:modal>
</section>