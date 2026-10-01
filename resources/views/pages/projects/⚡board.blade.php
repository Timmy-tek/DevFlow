<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

use App\Models\Comment;
use App\Models\Label;

use Illuminate\Support\Facades\Validator;

new #[Title('Board')] class extends Component {
    public string $slug;

    // Task drawer form
    public ?int $taskId = null;
    public string $taskRef = '';
    public string $title = '';
    public string $description = '';
    public string $status = 'todo';
    public string $priority = 'medium';
    public ?string $due_date = null;
    public string $assignee_id = '';

    public string $commentBody = '';
    public string $newLabelName = '';
    public string $newLabelColor = 'violet';

    public string $managerLabelName = '';
    public string $managerLabelColor = 'violet';

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        abort_unless(Auth::user()->can('viewAny', Project::class), 403);
    }

    // Scoped to the current workspace: a slug alone is never trusted.
    #[Computed]
    public function project(): Project
    {
        return Auth::user()->currentWorkspace->projects()->where('slug', $this->slug)->firstOrFail();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('create', [Task::class, $this->project]);
    }

    #[Computed]
    public function columns(): array
    {
        $tasks = $this->project->tasks()
            ->with(['assignee', 'labels'])
            ->withCount('comments')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn(Task $task) => $task->status->value);

        return collect(TaskStatus::cases())
            ->mapWithKeys(fn(TaskStatus $s) => [$s->value => $tasks->get($s->value, collect())])
            ->all();
    }

    #[Computed]
    public function members()
    {
        return $this->project->workspace->members()->orderBy('name')->get();
    }

    #[Computed]
    public function workspaceLabels()
    {
        return $this->project->workspace->labels()->withCount('tasks')->orderBy('name')->get();
    }

    #[Computed]
    public function canDeleteLabels(): bool
    {
        return Auth::user()->roleIn($this->project->workspace)?->canManageWorkspace() ?? false;
    }

    #[Computed]
    public function taskLabelIds(): array
    {
        if (! $this->taskId) {
            return [];
        }

        return $this->project->tasks()->find($this->taskId)
            ?->labels()->pluck('labels.id')->map(fn($id) => (int) $id)->all() ?? [];
    }

    #[Computed]
    public function comments()
    {
        if (! $this->taskId) {
            return collect();
        }

        $task = $this->project->tasks()->find($this->taskId);

        return $task ? $task->comments()->with('author')->oldest()->get() : collect();
    }

    #[Computed]
    public function canComment(): bool
    {
        $task = $this->taskId ? $this->project->tasks()->find($this->taskId) : null;

        return $task ? Auth::user()->can('comment', $task) : false;
    }

    public function addLabel(): void
    {
        Gate::authorize('create', [Task::class, $this->project]);

        $validated = $this->validate([
            'managerLabelName' => [
                'required',
                'string',
                'max:30',
                Rule::unique('labels', 'name')->where('workspace_id', $this->project->workspace_id),
            ],
            'managerLabelColor' => ['required', Rule::in(Label::COLORS)],
        ]);

        $this->project->workspace->labels()->create([
            'name' => trim($validated['managerLabelName']),
            'color' => $validated['managerLabelColor'],
        ]);

        $this->reset('managerLabelName');
        unset($this->workspaceLabels);
    }

    public function updateLabel(int $id, string $name, string $color): void
    {
        $label = $this->project->workspace->labels()->findOrFail($id);
        Gate::authorize('update', $label);

        $validator = Validator::make(
            ['name' => trim($name), 'color' => $color],
            [
                'name' => [
                    'required',
                    'string',
                    'max:30',
                    Rule::unique('labels', 'name')->where('workspace_id', $label->workspace_id)->ignore($label->id),
                ],
                'color' => ['required', Rule::in(Label::COLORS)],
            ],
        );

        if ($validator->fails()) {
            $this->addError('labelManager', $validator->errors()->first());

            return;
        }

        $label->update($validator->validated());

        $this->resetErrorBag('labelManager');
        unset($this->workspaceLabels, $this->columns);
    }

    public function deleteLabel(int $id): void
    {
        $label = $this->project->workspace->labels()->findOrFail($id);
        Gate::authorize('delete', $label);

        $label->delete(); // the pivot rows cascade, so it disappears from every task

        unset($this->workspaceLabels, $this->taskLabelIds, $this->columns);
        Flux::toast(variant: 'success', text: 'Label deleted.');
    }

    public function toggleLabel(int $labelId): void
    {
        $task = $this->project->tasks()->findOrFail($this->taskId);
        Gate::authorize('update', $task);

        // Scoped to this workspace's labels only.
        $label = $this->project->workspace->labels()->findOrFail($labelId);
        $task->labels()->toggle($label->id);

        unset($this->columns, $this->taskLabelIds);
    }

    public function createLabel(): void
    {
        Gate::authorize('create', [Task::class, $this->project]);

        $validated = $this->validate([
            'newLabelName' => [
                'required',
                'string',
                'max:30',
                Rule::unique('labels', 'name')->where('workspace_id', $this->project->workspace_id),
            ],
            'newLabelColor' => ['required', Rule::in(Label::COLORS)],
        ]);

        $label = $this->project->workspace->labels()->create([
            'name' => trim($validated['newLabelName']),
            'color' => $validated['newLabelColor'],
        ]);

        if ($this->taskId) {
            $this->project->tasks()->findOrFail($this->taskId)->labels()->attach($label->id);
        }

        $this->reset('newLabelName');
        unset($this->workspaceLabels, $this->taskLabelIds, $this->columns);
    }

    public function addComment(): void
    {
        $task = $this->project->tasks()->findOrFail($this->taskId);
        Gate::authorize('comment', $task);

        $validated = $this->validate([
            'commentBody' => ['required', 'string', 'max:2000'],
        ]);

        $task->comments()->create([
            'body' => trim($validated['commentBody']),
            'user_id' => Auth::id(),
        ]);

        $this->reset('commentBody');
        unset($this->comments, $this->columns);
    }

    public function deleteComment(int $id): void
    {
        $task = $this->project->tasks()->findOrFail($this->taskId);
        $comment = $task->comments()->findOrFail($id);
        Gate::authorize('delete', $comment);

        $comment->delete();

        unset($this->comments, $this->columns);
    }

    public function addTask(string $status, string $title): void
    {
        Gate::authorize('create', [Task::class, $this->project]);

        $status = TaskStatus::tryFrom($status);
        abort_if($status === null, 422);

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 120) {
            return;
        }

        $max = $this->project->tasks()->where('status', $status->value)->max('position');

        $this->project->tasks()->create([
            'title' => $title,
            'status' => $status,
            'position' => is_null($max) ? 0 : $max + 1,
            'created_by' => Auth::id(),
        ]);

        unset($this->columns);
    }

    /**
     * The browser sends the full board: ['todo' => [ids...], 'done' => [ids...], ...].
     * Safe to call repeatedly, and ids from other projects are ignored.
     */
    public function reorder(array $order): void
    {
        Gate::authorize('reorder', [Task::class, $this->project]);

        $valid = array_map(fn(TaskStatus $s) => $s->value, TaskStatus::cases());
        $tasks = $this->project->tasks()->get()->keyBy('id');

        DB::transaction(function () use ($order, $valid, $tasks) {
            foreach ($order as $status => $ids) {
                if (! in_array($status, $valid, true) || ! is_array($ids)) {
                    continue;
                }

                foreach (array_values($ids) as $position => $id) {
                    $task = $tasks->get((int) $id);

                    if (! $task) {
                        continue;
                    }

                    if ($task->status->value !== $status || (int) $task->position !== $position) {
                        $task->update(['status' => $status, 'position' => $position]);
                    }
                }
            }
        });

        unset($this->columns);
    }

    public function openTask(int $id): void
    {
        $task = $this->project->tasks()->findOrFail($id);
        Gate::authorize('view', $task);

        $this->taskId = $task->id;
        $this->taskRef = $this->project->key . '-' . $task->number;
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->status = $task->status->value;
        $this->priority = $task->priority->value;
        $this->due_date = $task->due_date?->format('Y-m-d');
        $this->assignee_id = (string) ($task->assignee_id ?? '');

        $this->resetErrorBag();

        $this->commentBody = '';

        Flux::modal('task-drawer')->show();
    }

    public function saveTask(): void
    {
        $task = $this->project->tasks()->findOrFail($this->taskId);
        Gate::authorize('update', $task);

        $validated = $this->validate([
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'due_date' => ['nullable', 'date'],
            'assignee_id' => [
                'nullable',
                Rule::exists('workspace_members', 'user_id')->where('workspace_id', $this->project->workspace_id),
            ],
        ]);

        $validated['description'] = $validated['description'] ?: null;
        $validated['due_date'] = $validated['due_date'] ?: null;
        $validated['assignee_id'] = filled($validated['assignee_id']) ? (int) $validated['assignee_id'] : null;

        // Changing status in the drawer sends the task to the bottom of the new column.
        if ($validated['status'] !== $task->status->value) {
            $max = $this->project->tasks()->where('status', $validated['status'])->max('position');
            $validated['position'] = is_null($max) ? 0 : $max + 1;
        }

        $task->update($validated);

        unset($this->columns);

        Flux::modal('task-drawer')->close();
        Flux::toast(variant: 'success', text: 'Task updated.');
    }

    public function deleteTask(): void
    {
        $task = $this->project->tasks()->findOrFail($this->taskId);
        Gate::authorize('delete', $task);

        $task->delete();

        $this->taskId = null;
        unset($this->columns);

        Flux::modal('task-drawer')->close();
        Flux::toast(variant: 'success', text: 'Task deleted.');
    }
}; ?>

@php
$project = $this->project;
$columns = $this->columns;
$selectClasses = 'w-full rounded-xl border border-zinc-300/70 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10';
@endphp

<section class="mx-auto flex w-full max-w-[110rem] flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <x-project-tabs :project="$project" active="board" />

        @if ($this->canEdit)
        <flux:modal.trigger name="labels-manager">
            <flux:button icon="tag" class="!rounded-full">Labels</flux:button>
        </flux:modal.trigger>
        @endif
    </div>

    <div
        class="grid auto-cols-[minmax(17rem,1fr)] grid-flow-col items-start gap-4 overflow-x-auto pb-4"
        x-data="{
            init() {
                if (! @js($this->canEdit) || ! window.Sortable) return;

                this.$root.querySelectorAll('[data-sortable-list]').forEach((el) => {
                    window.Sortable.create(el, {
                        group: 'tasks',
                        draggable: '[data-id]',
                        animation: 160,
                        emptyInsertThreshold: 32,
                        delay: 120,
                        delayOnTouchOnly: true,
                        ghostClass: 'opacity-40',
                        onEnd: (evt) => {
                            if (evt.from === evt.to && evt.oldIndex === evt.newIndex) return;
                            this.persist();
                        },
                    });
                });
            },
            persist() {
                const order = {};

                this.$root.querySelectorAll('[data-sortable-list]').forEach((el) => {
                    order[el.dataset.status] = [...el.querySelectorAll(':scope > [data-id]')].map((card) => card.dataset.id);
                });

                this.$wire.reorder(order);
            },
        }">
        @foreach ($columns as $statusValue => $tasks)
        @php $status = \App\Enums\TaskStatus::from($statusValue); @endphp

        <div wire:key="col-{{ $statusValue }}" x-data="{ adding: false, title: '' }" class="glass flex flex-col rounded-card p-3">
            <div class="mb-3 flex items-center justify-between px-2 pt-1">
                <div class="flex items-center gap-2">
                    <h2 class="font-medium">{{ $status->label() }}</h2>
                    <span class="rounded-full bg-zinc-900/5 px-2 py-0.5 text-xs dark:bg-white/10">{{ $tasks->count() }}</span>
                </div>

                @if ($this->canEdit)
                <button type="button" x-on:click="adding = true; $nextTick(() => $refs.input.focus())" class="rounded-full p-1 text-zinc-500 hover:bg-white/70 dark:text-zinc-400 dark:hover:bg-white/10" aria-label="Add task">
                    <flux:icon name="plus" class="size-4" />
                </button>
                @endif
            </div>

            <div data-sortable-list data-status="{{ $statusValue }}" class="flex min-h-24 flex-col gap-3">
                @foreach ($tasks as $task)
                <x-card
                    :tone="$task->priority->tone()"
                    data-id="{{ $task->id }}"
                    wire:key="task-{{ $task->id }}"
                    wire:click="openTask({{ $task->id }})"
                    :class="$status === \App\Enums\TaskStatus::Done ? 'opacity-70' : ''"
                    class="!rounded-2xl !p-4 cursor-pointer select-none transition hover:-translate-y-0.5">
                    <div class="flex items-center justify-between text-xs text-ink/60">
                        <span>{{ $project->key }}-{{ $task->number }}</span>
                        <span class="rounded-full bg-white/60 px-2 py-0.5 text-ink/70">{{ $task->priority->label() }}</span>
                    </div>

                    @if ($task->labels->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($task->labels as $label)
                        <x-label-chip :label="$label" />
                        @endforeach
                    </div>
                    @endif

                    <p @class(['mt-2 text-[15px] font-medium leading-snug', 'line-through'=> $status === \App\Enums\TaskStatus::Done])>
                        {{ $task->title }}
                    </p>

                    <div class="mt-4 flex min-h-6 items-center justify-between">
                        @if ($task->due_date)
                        <span @class([ 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs' , 'bg-ink text-white'=> $task->isOverdue(),
                            'bg-white/60' => ! $task->isOverdue(),
                            ])>
                            <flux:icon name="calendar" class="size-3.5" />
                            {{ $task->isOverdue() ? 'Overdue · ' : '' }}{{ $task->due_date->format('M j') }}
                        </span>
                        @else
                        <span></span>
                        @endif

                        <div class="flex items-center gap-2">
                            @if ($task->comments_count)
                            <span class="inline-flex items-center gap-1 text-xs text-ink/60">
                                <flux:icon name="chat-bubble-left" class="size-3.5" />{{ $task->comments_count }}
                            </span>
                            @endif

                            @if ($task->assignee)
                            <flux:avatar :name="$task->assignee->name" :initials="$task->assignee->initials()" size="xs" circle />
                            @endif
                        </div>
                    </div>
                </x-card>
                @endforeach
            </div>

            @if ($this->canEdit)
            <div class="mt-3">
                <button type="button" x-show="! adding" x-on:click="adding = true; $nextTick(() => $refs.input.focus())" class="flex w-full items-center gap-2 rounded-2xl px-3 py-2 text-sm text-zinc-500 hover:bg-white/70 dark:text-zinc-400 dark:hover:bg-white/10">
                    <flux:icon name="plus" class="size-4" /> Add task
                </button>

                <form
                    style="display: none"
                    x-show="adding"
                    x-on:submit.prevent="if (title.trim()) { $wire.addTask('{{ $statusValue }}', title); title = ''; }">
                    <input
                        x-ref="input"
                        x-model="title"
                        maxlength="120"
                        placeholder="Task title, then Enter"
                        x-on:keydown.escape="adding = false; title = ''"
                        x-on:blur="if (! title.trim()) adding = false"
                        class="w-full rounded-2xl border border-zinc-300/70 bg-white/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ink dark:border-white/15 dark:bg-white/10 dark:focus:ring-brand">
                </form>
            </div>
            @endif
        </div>
        @endforeach
    </div>

    <flux:modal name="task-drawer" variant="flyout" class="w-full max-w-lg">
        <form wire:submit="saveTask" class="space-y-6">
            <div>
                <flux:heading size="lg">Task</flux:heading>
                <flux:subheading>{{ $taskRef }}</flux:subheading>
            </div>

            <flux:input wire:model="title" label="Title" :disabled="! $this->canEdit" />
            <flux:textarea wire:model="description" label="Description" rows="6" :disabled="! $this->canEdit" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>Status</flux:label>
                    <select wire:model="status" @disabled(! $this->canEdit) class="{{ $selectClasses }}">
                        @foreach (\App\Enums\TaskStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <flux:error name="status" />
                </flux:field>

                <flux:field>
                    <flux:label>Priority</flux:label>
                    <select wire:model="priority" @disabled(! $this->canEdit) class="{{ $selectClasses }}">
                        @foreach (\App\Enums\TaskPriority::cases() as $p)
                        <option value="{{ $p->value }}">{{ $p->label() }}</option>
                        @endforeach
                    </select>
                    <flux:error name="priority" />
                </flux:field>

                <flux:input wire:model="due_date" type="date" label="Due date" :disabled="! $this->canEdit" />

                <flux:field>
                    <flux:label>Assignee</flux:label>
                    <select wire:model="assignee_id" @disabled(! $this->canEdit) class="{{ $selectClasses }}">
                        <option value="">Unassigned</option>
                        @foreach ($this->members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                    <flux:error name="assignee_id" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Labels</flux:label>

                <div class="flex flex-wrap gap-2">
                    @forelse ($this->workspaceLabels as $label)
                    @php $on = in_array($label->id, $this->taskLabelIds); @endphp
                    <button
                        type="button"
                        wire:key="label-{{ $label->id }}"
                        wire:click="toggleLabel({{ $label->id }})"
                        @disabled(! $this->canEdit)
                        @class([
                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs transition',
                        'bg-ink text-white dark:bg-brand dark:text-ink' => $on,
                        'bg-white/60 text-ink/80 hover:bg-white dark:bg-white/10 dark:text-white/80' => ! $on,
                        ])
                        >
                        <span class="size-1.5 rounded-full {{ $label->dotClass() }}"></span>{{ $label->name }}
                    </button>
                    @empty
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">No labels yet.</span>
                    @endforelse
                </div>

                @if ($this->canEdit)
                <div x-data="{ open: false }" class="mt-2">
                    <button type="button" x-show="! open" x-on:click="open = true" class="text-xs text-zinc-500 hover:underline dark:text-zinc-400">+ New label</button>

                    <div x-show="open" style="display: none" class="flex flex-wrap items-center gap-2">
                        <input
                            wire:model="newLabelName"
                            wire:keydown.enter.prevent="createLabel"
                            maxlength="30"
                            placeholder="Label name"
                            class="rounded-full border border-zinc-300/70 bg-white/70 px-3 py-1 text-xs dark:border-white/15 dark:bg-white/10">
                        <select wire:model="newLabelColor" class="rounded-full border border-zinc-300/70 bg-white/70 px-3 py-1 text-xs dark:border-white/15 dark:bg-white/10">
                            @foreach (\App\Models\Label::COLORS as $c)
                            <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="createLabel" class="rounded-full bg-ink px-3 py-1 text-xs text-white dark:bg-brand dark:text-ink">Add</button>
                    </div>

                    @error('newLabelName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
                @endif
            </flux:field>

            @if ($this->canEdit)
            <div class="flex items-center justify-between">
                <flux:button variant="ghost" icon="trash" wire:click="deleteTask" wire:confirm="Delete this task?">Delete</flux:button>
                <flux:button type="submit" variant="primary">Save changes</flux:button>
            </div>
            @endif
        </form>

        @if ($taskId)
        <div class="mt-8 border-t border-zinc-200/70 pt-6 dark:border-white/10">
            <flux:heading>Comments <span class="text-zinc-500 dark:text-zinc-400">({{ $this->comments->count() }})</span></flux:heading>

            <ul class="mt-4 space-y-4">
                @forelse ($this->comments as $comment)
                <li wire:key="comment-{{ $comment->id }}" class="flex gap-3">
                    <flux:avatar :name="$comment->author?->name ?? 'Deleted user'" :initials="$comment->author?->initials() ?? '?'" size="xs" circle />

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 text-xs">
                            <span class="text-sm font-medium">{{ $comment->author?->name ?? 'Deleted user' }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ $comment->created_at->diffForHumans() }}</span>

                            @can('delete', $comment)
                            <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="Delete this comment?" class="ms-auto text-zinc-400 hover:text-red-500" aria-label="Delete comment">
                                <flux:icon name="trash" class="size-3.5" />
                            </button>
                            @endcan
                        </div>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $comment->body }}</p>
                    </div>
                </li>
                @empty
                <li class="text-sm text-zinc-500 dark:text-zinc-400">No comments yet.</li>
                @endforelse
            </ul>

            @if ($this->canComment)
            <form wire:submit="addComment" class="mt-4 flex flex-col gap-2">
                <flux:textarea wire:model="commentBody" rows="2" placeholder="Write a comment…" />
                <div class="flex justify-end">
                    <flux:button type="submit" size="sm" variant="primary">Comment</flux:button>
                </div>
            </form>
            @endif
        </div>
        @endif
    </flux:modal>

    <flux:modal name="labels-manager" class="w-full max-w-xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Labels</flux:heading>
                <flux:subheading>Shared across every project in {{ $project->workspace->name }}.</flux:subheading>
            </div>

            @error('labelManager')
            <p class="rounded-xl bg-rose px-3 py-2 text-sm text-ink">{{ $message }}</p>
            @enderror

            <ul class="space-y-2">
                @forelse ($this->workspaceLabels as $label)
                <li
                    wire:key="manage-label-{{ $label->id }}"
                    class="flex items-center gap-2 rounded-2xl bg-white/70 px-3 py-2 dark:bg-white/10"
                    x-data="{
                        name: @js($label->name),
                        color: @js($label->color),
                        saved: @js($label->name.'|'.$label->color),
                        save() {
                            const value = this.name.trim() + '|' + this.color;
                            if (! this.name.trim() || value === this.saved) return;
                            this.saved = value;
                            this.$wire.updateLabel({{ $label->id }}, this.name, this.color);
                        },
                    }">
                    <span class="size-2.5 shrink-0 rounded-full {{ $label->dotClass() }}"></span>

                    <input
                        x-model="name"
                        x-on:blur="save()"
                        x-on:keydown.enter.prevent="$el.blur()"
                        maxlength="30"
                        class="min-w-0 flex-1 bg-transparent text-sm outline-none">

                    <select
                        x-model="color"
                        x-on:change="$nextTick(() => save())"
                        class="rounded-full border border-zinc-300/70 bg-white/70 px-2 py-1 text-xs dark:border-white/15 dark:bg-white/10">
                        @foreach (\App\Models\Label::COLORS as $c)
                        <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                        @endforeach
                    </select>

                    <span class="w-16 shrink-0 text-end text-xs text-zinc-500 dark:text-zinc-400">
                        {{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}
                    </span>

                    @if ($this->canDeleteLabels)
                    <button
                        type="button"
                        wire:click="deleteLabel({{ $label->id }})"
                        wire:confirm="Delete “{{ $label->name }}”? It will be removed from {{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}."
                        class="text-zinc-400 hover:text-red-500"
                        aria-label="Delete label">
                        <flux:icon name="trash" class="size-4" />
                    </button>
                    @endif
                </li>
                @empty
                <li class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No labels yet. Add your first one below.</li>
                @endforelse
            </ul>

            <div class="border-t border-zinc-200/70 pt-4 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        wire:model="managerLabelName"
                        wire:keydown.enter.prevent="addLabel"
                        maxlength="30"
                        placeholder="New label name"
                        class="min-w-0 flex-1 rounded-full border border-zinc-300/70 bg-white/70 px-4 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                    <select wire:model="managerLabelColor" class="rounded-full border border-zinc-300/70 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                        @foreach (\App\Models\Label::COLORS as $c)
                        <option value="{{ $c }}">{{ ucfirst($c) }}</option>
                        @endforeach
                    </select>
                    <flux:button wire:click="addLabel" variant="primary" class="!rounded-full">Add</flux:button>
                </div>
                @error('managerLabelName') <p class="mt-2 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>
        </div>
    </flux:modal>

</section>