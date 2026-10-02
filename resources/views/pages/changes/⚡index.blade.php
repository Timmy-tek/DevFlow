<?php

use App\Models\ChangeRequest;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Change requests')]
    class extends Component {
    public string $slug;

    #[Url]
    public string $filter = 'open';

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
        return Auth::user()->can('create', [ChangeRequest::class, $this->project]);
    }

    #[Computed]
    public function counts(): array
    {
        $counts = $this->project->changeRequests()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'open' => (int) ($counts['open'] ?? 0),
            'merged' => (int) ($counts['merged'] ?? 0),
            'closed' => (int) ($counts['closed'] ?? 0),
        ];
    }

    #[Computed]
    public function requests()
    {
        $filter = in_array($this->filter, ['open', 'merged', 'closed'], true) ? $this->filter : 'open';

        return $this->project->changeRequests()
            ->where('status', $filter)
            ->with(['author', 'task'])
            ->withCount('revisions')
            ->latest('id')
            ->get();
    }
}; ?>

@php $project = $this->project; @endphp

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" wire:navigate
            class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <x-project-tabs :project="$project" active="changes" />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="glass inline-flex gap-1 rounded-full p-1.5">
            @foreach (['open' => 'Open', 'merged' => 'Merged', 'closed' => 'Closed'] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                    'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition',
                    'bg-brand text-ink' => $filter === $key,
                    'text-zinc-600 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-white/10' => $filter !== $key,
                ])>
                    {{ $label }}
                    <span class="rounded-full bg-black/10 px-1.5 text-xs">{{ $this->counts[$key] }}</span>
                </button>
            @endforeach
        </div>

        @if ($this->canCreate)
            <flux:button :href="route('changes.create', $project->slug)" wire:navigate variant="primary" icon="plus"
                class="!rounded-full">
                New change request
            </flux:button>
        @endif
    </div>

    <div class="grid gap-3">
        @forelse ($this->requests as $cr)
            @php [$badgeLabel, $badgeClass] = $cr->badge(); @endphp

            <a wire:key="cr-{{ $cr->id }}" href="{{ route('changes.show', [$project->slug, $cr->number]) }}" wire:navigate
                class="glass flex items-center gap-4 rounded-2xl px-5 py-4 transition hover:-translate-y-0.5">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium">
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $cr->ref() }}</span> {{ $cr->title }}
                    </p>
                    <p class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $cr->author?->name ?? 'Someone' }} · {{ $cr->created_at->diffForHumans() }} ·
                        {{ $cr->revisions_count }} {{ $cr->revisions_count === 1 ? 'revision' : 'revisions' }}
                    </p>
                </div>

                @if ($cr->task)
                    <span
                        class="hidden shrink-0 rounded-full bg-sky px-3 py-1 text-xs text-ink sm:inline">{{ $project->key }}-{{ $cr->task->number }}</span>
                @endif

                <span class="shrink-0 rounded-full px-3 py-1 text-xs {{ $badgeClass }}">{{ $badgeLabel }}</span>
            </a>
        @empty
            <x-card class="grid place-items-center gap-2 py-16 text-center">
                <h2 class="text-2xl font-light">No {{ $filter }} change requests</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $filter === 'open' && $this->canCreate ? 'Propose a change to a file and get it reviewed.' : 'Nothing here yet.' }}
                </p>
            </x-card>
        @endforelse
    </div>
</section>