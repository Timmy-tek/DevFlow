<?php

use App\Models\ChangeRequest;
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

new #[Title('Release')]
    class extends Component {
    public string $slug;
    public int $releaseId;

    public string $version = '';
    public string $title = '';
    public string $notes = '';

    public function mount(string $slug, int $id): void
    {
        $this->slug = $slug;
        $this->releaseId = $id;

        Gate::authorize('view', $this->release);

        $this->version = $this->release->version;
        $this->title = $this->release->title ?? '';
        $this->notes = $this->release->notes ?? '';
    }

    #[Computed]
    public function project(): Project
    {
        return Auth::user()->currentWorkspace->projects()->where('slug', $this->slug)->firstOrFail();
    }

    // Looked up through the project, so an id from another workspace 404s.
    #[Computed]
    public function release(): Release
    {
        return $this->project->releases()->with(['author', 'publisher'])->findOrFail($this->releaseId);
    }

    #[Computed]
    public function crs()
    {
        return $this->release->changeRequests()->with(['task.labels', 'author'])->orderBy('number')->get();
    }

    #[Computed]
    public function unreleased()
    {
        return app(ReleaseService::class)->unreleased($this->project);
    }

    #[Computed]
    public function files(): array
    {
        return app(ReleaseService::class)->filesFor($this->release);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('update', $this->release);
    }

    #[Computed]
    public function canPublish(): bool
    {
        return Auth::user()->can('publish', $this->release);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->can('delete', $this->release);
    }

    protected function forgetCaches(): void
    {
        unset($this->release, $this->crs, $this->unreleased, $this->files);
    }

    // Validates the form and saves it to the draft.
    protected function persist(Release $release): void
    {
        $validated = $this->validate([
            'version' => [
                'required',
                'string',
                'max:32',
                'regex:' . ReleaseVersion::PATTERN,
                Rule::unique('releases', 'version')->where('project_id', $release->project_id)->ignore($release->id),
            ],
            'title' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ], [
            'version.regex' => 'Use a version like v1.2.0.',
        ]);

        $release->update([
            'version' => $validated['version'],
            'title' => trim($validated['title'] ?? '') ?: null,
            'notes' => $validated['notes'] ?? null,
        ]);
    }

    public function save(): void
    {
        $release = $this->release;
        Gate::authorize('update', $release);

        $this->persist($release);

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Draft saved.');
    }

    public function regenerate(): void
    {
        $release = $this->release;
        Gate::authorize('update', $release);

        $this->notes = app(ReleaseService::class)->generateNotes($release);
        $release->update(['notes' => $this->notes]);

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Notes regenerated from the included changes.');
    }

    public function addChangeRequest(string $changeRequestId): void
    {
        $release = $this->release;
        Gate::authorize('update', $release);

        $cr = $this->unreleased->firstWhere('id', (int) $changeRequestId);
        abort_unless($cr, 422);

        ChangeRequest::whereKey($cr->id)->update(['release_id' => $release->id]);

        $this->forgetCaches();
    }

    public function removeChangeRequest(int $changeRequestId): void
    {
        $release = $this->release;
        Gate::authorize('update', $release);

        $release->changeRequests()->whereKey($changeRequestId)->update(['release_id' => null]);

        $this->forgetCaches();
    }

    public function publish(): void
    {
        $release = $this->release;
        Gate::authorize('publish', $release);

        $this->resetErrorBag('publish');
        $this->persist($release);

        try {
            app(ReleaseService::class)->publish($release, Auth::id());
        } catch (\RuntimeException $e) {
            $this->addError('publish', $e->getMessage());
            $this->forgetCaches();

            return;
        }

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Release published.');
    }

    public function deleteDraft(): void
    {
        $release = $this->release;
        Gate::authorize('delete', $release);

        // The change requests go back to "unreleased" automatically (release_id is nulled).
        $release->delete();

        $this->redirectRoute('releases.index', ['slug' => $this->slug], navigate: true);
    }
}; ?>

@php
    $release = $this->release;
    $project = $this->project;
    $published = $release->isPublished();
@endphp

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('releases.index', $project->slug) }}" wire:navigate
                class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Releases</a>
            <div class="mt-1 flex flex-wrap items-center gap-3">
                <h1 class="text-4xl font-light tracking-tight sm:text-5xl">{{ $release->version }}</h1>
                <span @class([
                    'rounded-full px-3 py-1 text-xs',
                    'bg-lavender text-ink' => $published,
                    'bg-butter text-ink' => !$published,
                ])>{{ $published ? 'Published' : 'Draft' }}</span>
            </div>
            @if ($release->title)
                <p class="mt-1 text-lg font-light text-zinc-600 dark:text-zinc-300">{{ $release->title }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($this->canEdit)
                <flux:button wire:click="save" class="!rounded-full">Save draft</flux:button>
            @endif

            @if ($this->canPublish)
                <button type="button" wire:click="publish"
                    wire:confirm="Publish {{ $release->version }}? Published releases can't be edited."
                    class="rounded-full bg-ink px-6 py-2 text-sm font-medium text-white dark:bg-brand dark:text-ink">
                    Publish
                </button>
            @endif
        </div>
    </div>

    @error('publish')
    <p class="rounded-2xl bg-rose px-4 py-3 text-sm text-ink">{{ $message }}</p> @enderror

    <div class="grid gap-4 lg:grid-cols-12">
        <div class="flex flex-col gap-4 lg:col-span-8">
            @if ($published)
                <x-card>
                    <div
                        class="text-sm leading-relaxed [&_h3]:mb-2 [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-medium [&_h3:first-child]:mt-0 [&_li]:my-1 [&_ul]:list-disc [&_ul]:ps-5">
                        {!! \Illuminate\Support\Str::markdown($release->notes ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                    </div>
                </x-card>
            @else
                <x-card class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="version" label="Version" :disabled="! $this->canEdit" />
                        <flux:input wire:model="title" label="Title (optional)" class="sm:col-span-2"
                            :disabled="! $this->canEdit" />
                    </div>

                    <flux:field>
                        <div class="flex items-center justify-between gap-3">
                            <flux:label>Release notes (Markdown)</flux:label>
                            @if ($this->canEdit)
                                <button type="button" wire:click="regenerate"
                                    wire:confirm="Rebuild the notes from the included change requests? This replaces anything you've written."
                                    class="text-xs underline underline-offset-2">
                                    Regenerate from changes
                                </button>
                            @endif
                        </div>
                        <textarea wire:model="notes" rows="16" @disabled(!$this->canEdit)
                            class="w-full rounded-xl border border-zinc-300/70 bg-white/70 px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-ink dark:border-white/15 dark:bg-white/10 dark:focus:ring-brand"></textarea>
                        <flux:error name="notes" />
                    </flux:field>
                </x-card>

                <x-card>
                    <h2 class="text-lg font-medium">Preview</h2>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">Shows the last saved notes.</p>
                    <div
                        class="mt-4 text-sm leading-relaxed [&_h3]:mb-2 [&_h3]:mt-6 [&_h3]:text-base [&_h3]:font-medium [&_h3:first-child]:mt-0 [&_li]:my-1 [&_ul]:list-disc [&_ul]:ps-5">
                        {!! \Illuminate\Support\Str::markdown($release->notes ?? '', ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                    </div>
                </x-card>
            @endif
        </div>

        <div class="flex flex-col gap-4 lg:col-span-4">
            <x-card tone="dark" class="flex flex-col gap-4">
                <div>
                    <h2 class="text-lg font-medium">{{ $published ? 'Shipped' : 'Included changes' }}</h2>
                    <p class="text-xs text-white/60">
                        @if ($published)
                            {{ $this->crs->count() }} {{ $this->crs->count() === 1 ? 'change' : 'changes' }} · published by
                            {{ $release->publisher?->name ?? 'someone' }} {{ $release->published_at?->diffForHumans() }}
                        @else
                            {{ $this->crs->count() }} {{ $this->crs->count() === 1 ? 'change' : 'changes' }} in this draft
                        @endif
                    </p>
                </div>

                <ul class="flex flex-col gap-2 text-sm">
                    @forelse ($this->crs as $cr)
                        <li wire:key="inc-{{ $cr->id }}" class="flex items-start gap-2">
                            <a href="{{ route('changes.show', [$project->slug, $cr->number]) }}" wire:navigate
                                class="min-w-0 flex-1 hover:underline">
                                <span class="text-white/60">{{ $cr->ref() }}</span> {{ $cr->title }}
                                @if ($cr->task)
                                    <span class="block text-xs text-white/50">
                                        {{ $project->key }}-{{ $cr->task->number }}@if ($cr->task->labels->isNotEmpty()) ·
                                        {{ $cr->task->labels->sortBy('name')->first()->name }}@endif
                                    </span>
                                @endif
                            </a>

                            @if ($this->canEdit)
                                <button type="button" wire:click="removeChangeRequest({{ $cr->id }})"
                                    class="mt-0.5 text-white/40 hover:text-white" aria-label="Remove from this release">
                                    <flux:icon name="x-mark" class="size-4" />
                                </button>
                            @endif
                        </li>
                    @empty
                        <li class="text-white/60">No changes yet.</li>
                    @endforelse
                </ul>

                @if ($this->canEdit && $this->unreleased->isNotEmpty())
                    <select wire:change="addChangeRequest($event.target.value)"
                        class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs text-white">
                        <option value="" class="text-ink">Add an unreleased change…</option>
                        @foreach ($this->unreleased as $cr)
                            <option value="{{ $cr->id }}" class="text-ink">{{ $cr->ref() }} · {{ str($cr->title)->limit(36) }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </x-card>

            @if ($this->files !== [])
                <x-card>
                    <h3 class="text-sm text-zinc-500 dark:text-zinc-400">Files updated</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($this->files as $file)
                            <li class="flex justify-between gap-3">
                                <span class="truncate">{{ $file['name'] }}</span>
                                <span
                                    class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ implode(', ', $file['versions']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            @if ($this->canDelete)
                <button type="button" wire:click="deleteDraft"
                    wire:confirm="Delete this draft? Its changes go back to unreleased."
                    class="rounded-full border border-zinc-300/70 px-4 py-2 text-sm text-zinc-500 transition hover:text-red-500 dark:border-white/15 dark:text-zinc-400">
                    Delete draft
                </button>
            @endif
        </div>
    </div>
</section>