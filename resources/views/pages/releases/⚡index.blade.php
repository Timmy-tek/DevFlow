<?php

use App\Models\Project;
use App\Models\Release;
use App\Services\ReleaseService;
use App\Support\ReleaseVersion;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Releases')]
    class extends Component {
    public string $slug;

    public string $version = '';
    public string $title = '';
    public array $selected = [];

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        abort_unless(Auth::user()->can('viewAny', Project::class), 403);
    }

    #[Computed]
    public function project(): Project
    {
        return Auth::user()->currentWorkspace->projects()->where('slug', $this->slug)->firstOrFail();
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Auth::user()->can('create', [Release::class, $this->project]);
    }

    #[Computed]
    public function unreleased()
    {
        return app(ReleaseService::class)->unreleased($this->project);
    }

    // Drafts first, then published releases, newest first.
    #[Computed]
    public function releases()
    {
        return $this->project->releases()
            ->withCount('changeRequests')
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function latestVersion(): ?string
    {
        return ReleaseVersion::latest(
            $this->project->releases()->where('status', Release::PUBLISHED)->pluck('version')->all()
        );
    }

    public function openCreate(): void
    {
        Gate::authorize('create', [Release::class, $this->project]);

        $this->resetErrorBag();

        // Suggest the next version after anything that exists, drafts included.
        $this->version = ReleaseVersion::next(
            ReleaseVersion::latest($this->project->releases()->pluck('version')->all())
        );
        $this->title = '';
        $this->selected = $this->unreleased->pluck('id')->map(fn($id) => (string) $id)->all();

        Flux::modal('new-release')->show();
    }

    public function createDraft(): void
    {
        $project = $this->project;
        Gate::authorize('create', [Release::class, $project]);

        $validated = $this->validate([
            'version' => [
                'required',
                'string',
                'max:32',
                'regex:' . ReleaseVersion::PATTERN,
                Rule::unique('releases', 'version')->where('project_id', $project->id),
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => [Rule::in($this->unreleased->pluck('id')->all())],
        ], [
            'selected.required' => 'Pick at least one change request.',
            'selected.min' => 'Pick at least one change request.',
            'version.regex' => 'Use a version like v1.2.0.',
        ]);

        $release = app(ReleaseService::class)->createDraft(
            $project,
            $validated['version'],
            trim($validated['title'] ?? '') ?: null,
            array_map('intval', $validated['selected']),
            Auth::id(),
        );

        $this->redirectRoute('releases.show', ['slug' => $project->slug, 'id' => $release->id], navigate: true);
    }
}; ?>

@php $project = $this->project; @endphp

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" wire:navigate
            class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <x-project-tabs :project="$project" active="releases" />

    {{-- Unreleased changes --}}
    <x-card tone="dark" class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-lg font-medium">Unreleased changes</h2>
            @if ($this->unreleased->isEmpty())
                <p class="mt-1 text-sm text-white/60">Everything merged has shipped in a release. Merged change requests
                    will collect here.</p>
            @else
                <p class="mt-1 text-sm text-white/60">
                    {{ $this->unreleased->count() }} merged
                    {{ $this->unreleased->count() === 1 ? 'change request is' : 'change requests are' }} waiting to ship
                </p>
                <ul class="mt-3 flex flex-wrap gap-1.5 text-xs">
                    @foreach ($this->unreleased->take(6) as $cr)
                        <li class="rounded-full bg-white/15 px-2.5 py-1">{{ $cr->ref() }} · {{ str($cr->title)->limit(28) }}
                        </li>
                    @endforeach
                    @if ($this->unreleased->count() > 6)
                        <li class="rounded-full bg-white/15 px-2.5 py-1">+{{ $this->unreleased->count() - 6 }} more</li>
                    @endif
                </ul>
            @endif
        </div>

        @if ($this->canCreate && $this->unreleased->isNotEmpty())
            <button type="button" wire:click="openCreate"
                class="shrink-0 rounded-full bg-brand px-6 py-2.5 text-sm font-medium text-ink">Draft a release</button>
        @endif
    </x-card>

    {{-- Releases --}}
    <div class="grid gap-3">
        @forelse ($this->releases as $release)
            <a wire:key="release-{{ $release->id }}" href="{{ route('releases.show', [$project->slug, $release->id]) }}"
                wire:navigate class="glass flex items-center gap-4 rounded-2xl px-5 py-4 transition hover:-translate-y-0.5">
                <span @class([
                    'shrink-0 rounded-full px-3 py-1 text-sm font-medium',
                    'bg-brand text-ink' => $release->version === $this->latestVersion && $release->isPublished(),
                    'bg-white/70 text-ink dark:bg-white/10 dark:text-white' => !($release->version === $this->latestVersion && $release->isPublished()),
                ])>{{ $release->version }}</span>

                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">{{ $release->title ?: 'Release ' . $release->version }}</p>
                    <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $release->change_requests_count }}
                        {{ $release->change_requests_count === 1 ? 'change' : 'changes' }}
                        ·
                        {{ $release->isPublished() ? 'published ' . $release->published_at->diffForHumans() : 'drafted ' . $release->created_at->diffForHumans() }}
                    </p>
                </div>

                <span @class([
                    'shrink-0 rounded-full px-3 py-1 text-xs',
                    'bg-butter text-ink' => !$release->isPublished(),
                    'bg-lavender text-ink' => $release->isPublished(),
                ])>{{ $release->isPublished() ? ($release->version === $this->latestVersion ? 'Latest' : 'Published') : 'Draft' }}</span>
            </a>
        @empty
            <x-card class="grid place-items-center gap-2 py-16 text-center">
                <h2 class="text-2xl font-light">No releases yet</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Merge a change request, then bundle it into your first
                    release.</p>
            </x-card>
        @endforelse
    </div>

    <flux:modal name="new-release" class="w-full max-w-lg">
        <form wire:submit="createDraft" class="space-y-6">
            <div>
                <flux:heading size="lg">Draft a release</flux:heading>
                <flux:subheading>Notes are generated from the change requests you pick, grouped by label.
                </flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="version" label="Version" placeholder="v0.1.0" class="sm:col-span-1" />
                <flux:input wire:model="title" label="Title (optional)" placeholder="Brand refresh"
                    class="sm:col-span-2" />
            </div>

            <flux:field>
                <flux:label>Include</flux:label>
                <ul class="grid max-h-64 gap-2 overflow-y-auto">
                    @foreach ($this->unreleased as $cr)
                        <li wire:key="pick-{{ $cr->id }}">
                            <label
                                class="flex cursor-pointer items-center gap-3 rounded-2xl bg-white/70 px-4 py-2.5 text-sm dark:bg-white/10">
                                <input type="checkbox" wire:model="selected" value="{{ $cr->id }}">
                                <span class="min-w-0 flex-1 truncate">
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $cr->ref() }}</span> {{ $cr->title }}
                                </span>
                                @if ($cr->task?->labels->isNotEmpty())
                                    <span
                                        class="shrink-0 rounded-full bg-white/70 px-2 py-0.5 text-[11px] text-ink">{{ $cr->task->labels->sortBy('name')->first()->name }}</span>
                                @endif
                            </label>
                        </li>
                    @endforeach
                </ul>
                <flux:error name="selected" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create draft</flux:button>
            </div>
        </form>
    </flux:modal>
</section>