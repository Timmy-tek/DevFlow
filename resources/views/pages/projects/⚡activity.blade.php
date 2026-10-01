<?php

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Activity')]
    class extends Component {
    public string $slug;

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
    public function logs()
    {
        return ActivityLog::where('project_id', $this->project->id)
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->limit(60)
            ->get();
    }
}; ?>

@php $project = $this->project; @endphp

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" wire:navigate
            class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <x-project-tabs :project="$project" active="activity" />

    <x-card>
        @if ($this->logs->isEmpty())
            <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                Nothing yet. Create, move or comment on a task and it shows up here.
            </p>
        @else
            <ol class="relative ms-2 border-s border-zinc-300/60 dark:border-white/15">
                @foreach ($this->logs as $log)
                    <li wire:key="log-{{ $log->id }}" class="relative pb-6 ps-6 last:pb-0">
                        <span
                            class="absolute -left-[7px] top-1.5 size-3 rounded-full bg-brand ring-4 ring-white/70 dark:ring-white/10"></span>
                        <p class="text-sm">
                            <span class="font-medium">{{ $log->actor?->name ?? 'Someone' }}</span>
                            {{ $log->describe() }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $log->created_at->diffForHumans() }}</p>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-card>
</section>