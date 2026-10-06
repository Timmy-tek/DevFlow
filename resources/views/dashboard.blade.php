<x-layouts::app :title="__('Dashboard')">
    @php
        $workspace = auth()->user()->currentWorkspace;
        $projects = $workspace->projects()->latest()->take(3)->get();

        $workspaceTasks = \App\Models\Task::whereHas('project', fn($q) => $q->where('workspace_id', $workspace->id));
        $openTasks = (clone $workspaceTasks)->where('status', '!=', 'done')->count();
        $inReview = (clone $workspaceTasks)->where('status', 'review')->count();
        $myTasks = (clone $workspaceTasks)
            ->where('assignee_id', auth()->id())
            ->where('status', '!=', 'done')
            ->with('project')
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $activity = \App\Models\ActivityLog::where('workspace_id', $workspace->id)
            ->with('actor')
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $waiting = \App\Models\ChangeRequest::query()
            ->whereHas('project', fn($q) => $q->where('workspace_id', $workspace->id))
            ->where('status', 'open')
            ->whereHas('reviewers', fn($q) => $q->where('users.id', auth()->id()))
            ->whereDoesntHave('reviews', fn($q) => $q->where('user_id', auth()->id()))
            ->with('project')
            ->latest('id')
            ->get();
    @endphp

    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">

        {{-- Greeting + big numerals --}}
        <div class="flex flex-wrap items-end justify-between gap-6">
            <div>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ now()->format('l, F j') }}</p>
                <h1 class="text-4xl font-light tracking-tight sm:text-5xl">
                    Hello, {{ str(auth()->user()->name)->before(' ') }}
                </h1>
            </div>
            <div class="flex items-end gap-10">
                @foreach ([['Projects', $workspace->projects()->count()], ['Open tasks', $openTasks], ['In review', $inReview]] as [$label, $value])
                    <div>
                        <div class="text-5xl font-light leading-none tracking-tight sm:text-6xl">{{ $value }}</div>
                        <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-12">
            {{-- Today --}}
            <x-card class="lg:col-span-3">
                <h2 class="text-lg font-medium">Today</h2>
                <ul class="mt-4 grid gap-3 text-sm">
                    @foreach ([['09:00', 'Daily sync'], ['11:30', 'Design review'], ['15:00', 'Release planning']] as [$time, $title])
                        <li class="flex items-center gap-3 rounded-2xl bg-white/70 px-3 py-2.5 dark:bg-white/10">
                            <span class="rounded-full bg-brand px-2 py-0.5 text-xs font-medium text-ink">{{ $time }}</span>
                            <span>{{ $title }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-card>

            {{-- My tasks --}}
            <x-card class="lg:col-span-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-medium">My tasks</h2>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $myTasks->count() }} open</span>
                </div>
                <div class="mt-4 grid gap-2 text-sm">
                    @forelse ($myTasks as $task)
                        <a href="{{ route('projects.board', $task->project->slug) }}" wire:navigate
                            class="flex items-center justify-between gap-3 rounded-2xl bg-white/70 px-4 py-3 transition hover:bg-white dark:bg-white/10 dark:hover:bg-white/15">
                            <span class="min-w-0 truncate">
                                <span
                                    class="text-zinc-500 dark:text-zinc-400">{{ $task->project->key }}-{{ $task->number }}</span>
                                {{ $task->title }}
                            </span>
                            <span
                                class="shrink-0 rounded-full border border-zinc-300/70 px-2.5 py-0.5 text-xs text-zinc-600 dark:border-white/15 dark:text-zinc-300">{{ $task->status->label() }}</span>
                        </a>
                    @empty
                        <p
                            class="rounded-2xl bg-white/70 px-4 py-6 text-center text-zinc-500 dark:bg-white/10 dark:text-zinc-400">
                            Nothing assigned to you. Enjoy the calm ✨</p>
                    @endforelse
                </div>
            </x-card>

            {{-- Review queue --}}
            <x-card tone="dark" class="flex flex-col justify-between lg:col-span-3">
                <h2 class="text-lg font-medium">Review queue</h2>
                <div class="my-6">
                    <div class="text-7xl font-light leading-none">{{ $waiting->count() }}</div>
                    <p class="mt-2 text-sm text-white/60">
                        {{ $waiting->count() === 1 ? 'change request is' : 'change requests are' }} waiting for you</p>
                </div>
                @if ($waiting->isNotEmpty())
                    <a href="{{ route('changes.show', [$waiting->first()->project->slug, $waiting->first()->number]) }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-full bg-brand px-5 py-2.5 text-sm font-medium text-ink">Open
                        next</a>
                @else
                    <span
                        class="inline-flex items-center justify-center rounded-full border border-white/15 px-5 py-2.5 text-sm text-white/60">All
                        caught up</span>
                @endif
            </x-card>

            {{-- Pastel project cards --}}
            @foreach ($projects as $project)
                <x-card :tone="$project->color" class="lg:col-span-4">
                    <div class="flex items-start justify-between">
                        <h3 class="text-lg font-medium">{{ $project->name }}</h3>
                        <span class="rounded-full bg-white/60 px-2.5 py-0.5 text-xs">{{ $project->status->label() }}</span>
                    </div>
                    <p class="mt-8 text-sm">
                        {{ $project->due_date ? 'Due ' . $project->due_date->format('M j') : 'No deadline' }}
                    </p>
                </x-card>
            @endforeach

            {{-- Activity --}}
            <x-card class="lg:col-span-12">
                <h2 class="text-lg font-medium">Recent activity</h2>
                <ul class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    @forelse ($activity as $log)
                        <li class="flex items-center gap-3">
                            <span class="size-2 shrink-0 rounded-full bg-brand ring-4 ring-brand/30"></span>
                            <span>
                                <span class="font-medium">{{ $log->actor?->name ?? 'Someone' }}</span>
                                {{ $log->describe() }}
                                <span class="text-zinc-500 dark:text-zinc-400">·
                                    {{ $log->created_at->diffForHumans() }}</span>
                            </span>
                        </li>
                    @empty
                        <li class="text-zinc-500 dark:text-zinc-400">No activity yet.</li>
                    @endforelse
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts::app>