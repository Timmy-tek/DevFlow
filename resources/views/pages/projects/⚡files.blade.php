<?php

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectFile;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

use App\Models\FileVersion;
use App\Services\FileUploadService;
use Livewire\Attributes\Url;

new #[Title('Files')]
    class extends Component {
    use WithFileUploads;

    public string $slug;

    public $uploads = [];

    public ?int $historyFileId = null;

    public string $forTask = '';

    #[Url]
    public string $taskFilter = '';

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
    public function canUpload(): bool
    {
        return Auth::user()->can('create', [ProjectFile::class, $this->project]);
    }

    #[Computed]
    public function canDelete(): bool
    {
        return Auth::user()->roleIn($this->project->workspace)?->canManageWorkspace() ?? false;
    }

    #[Computed]
    public function tasks()
    {
        return $this->project->tasks()->orderByDesc('number')->get();
    }

    #[Computed]
    public function files()
    {
        return $this->project->files()
            ->with(['latestVersion.uploader', 'latestVersion.task', 'latestVersion.changeRequest'])
            // "none" = files never linked to any task; a number = files with any version made for that task.
            ->when($this->taskFilter === 'none', fn($q) => $q->whereDoesntHave('versions', fn($v) => $v->whereNotNull('task_id')))
            ->when(ctype_digit($this->taskFilter), fn($q) => $q->whereHas('versions', fn($v) => $v->where('task_id', (int) $this->taskFilter)))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function historyFile(): ?ProjectFile
    {
        return $this->historyFileId ? $this->project->files()->find($this->historyFileId) : null;
    }

    #[Computed]
    public function historyVersions()
    {
        return $this->historyFile
            ? $this->historyFile->versions()->with(['uploader', 'task', 'changeRequest'])->orderByDesc('number')->get()
            : collect();
    }

    // Runs as soon as files finish uploading to Livewire's temporary storage.
    public function updatedUploads(): void
    {
        $project = $this->project;
        Gate::authorize('create', [ProjectFile::class, $project]);

        $this->resetErrorBag('uploads');

        $result = app(FileUploadService::class)->addNewFiles(
            $project,
            Arr::wrap($this->uploads),
            filled($this->forTask) ? (int) $this->forTask : null,
            Auth::id(),
        );

        $this->uploads = [];
        $this->forTask = ''; // reset on purpose, so a batch is never tagged by accident
        unset($this->files);

        if ($result['saved'] > 0) {
            Flux::toast(variant: 'success', text: $result['saved'] === 1 ? 'File uploaded.' : "{$result['saved']} files uploaded.");
        }

        if ($result['problems']) {
            $this->addError('uploads', implode(' ', $result['problems']));
        }
    }

    public function linkVersion(int $versionId, string $taskId): void
    {
        Gate::authorize('create', [ProjectFile::class, $this->project]);

        $version = FileVersion::whereHas('file', fn($q) => $q->where('project_id', $this->project->id))->findOrFail($versionId);
        $task = filled($taskId) ? $this->project->tasks()->findOrFail((int) $taskId) : null;

        $version->update(['task_id' => $task?->id]);

        unset($this->files, $this->historyVersions);
        Flux::toast(variant: 'success', text: $task ? 'Linked to ' . $this->project->key . '-' . $task->number . '.' : 'Task link removed.');
    }

    public function openHistory(int $id): void
    {
        $this->historyFileId = $this->project->files()->findOrFail($id)->id;

        Flux::modal('file-history')->show();
    }

    public function deleteFile(int $id): void
    {
        $file = $this->project->files()->findOrFail($id);
        Gate::authorize('delete', $file);

        $file->delete();

        ActivityLog::create([
            'workspace_id' => $this->project->workspace_id,
            'project_id' => $this->project->id,
            'user_id' => Auth::id(),
            'action' => 'file.deleted',
            'properties' => ['name' => $file->name],
        ]);

        unset($this->files);
        Flux::toast(variant: 'success', text: 'File moved to the recycle bin.');
    }
}; ?>

@php $project = $this->project; @endphp

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <a href="{{ route('projects.index') }}" wire:navigate
            class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <x-project-tabs :project="$project" active="files" />

    @if ($this->canUpload)
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <label for="for-task" class="text-sm text-zinc-500 dark:text-zinc-400">These files are for</label>
                <select id="for-task" wire:model.live="forTask"
                    class="rounded-full border border-zinc-300/70 bg-white/70 px-4 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                    <option value="">No task (project file)</option>
                    @foreach ($this->tasks as $t)
                        <option value="{{ $t->id }}">{{ $project->key }}-{{ $t->number }} · {{ str($t->title)->limit(40) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <x-dropzone wire:model="uploads"
                hint="Up to 10 MB each. New files only: changes to existing files go through a change request." />

            @error('uploads')
            <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            @error('uploads.*')
            <p class="text-sm text-red-500">{{ $message }}</p> @enderror
        </div>
    @endif

    <div class="flex items-center gap-3">
        <label for="task-filter" class="text-sm text-zinc-500 dark:text-zinc-400">Show</label>
        <select id="task-filter" wire:model.live="taskFilter"
            class="rounded-full border border-zinc-300/70 bg-white/70 px-4 py-2 text-sm dark:border-white/15 dark:bg-white/10">
            <option value="">All files</option>
            <option value="none">Project files (no task)</option>
            @foreach ($this->tasks as $t)
                <option value="{{ $t->id }}">{{ $project->key }}-{{ $t->number }} · {{ str($t->title)->limit(40) }}</option>
            @endforeach
        </select>
    </div>

    @if ($this->files->isEmpty())
        <x-card class="grid place-items-center gap-2 py-16 text-center">
            <h2 class="text-2xl font-light">No files yet</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ $this->canUpload ? 'Upload your first file to start its version history.' : 'Nothing has been uploaded to this project yet.' }}
            </p>
        </x-card>
    @else
        <x-card>
            <ul class="grid gap-2">
                @foreach ($this->files as $file)
                    @php
                        $v = $file->latestVersion;
                        $ext = str(pathinfo($file->name, PATHINFO_EXTENSION))->limit(4, '');
                    @endphp

                    <li wire:key="file-{{ $file->id }}"
                        class="flex items-center gap-4 rounded-2xl bg-white/70 px-4 py-3 dark:bg-white/10">
                        @if ($v->isImage())
                            <img src="{{ route('files.preview', $v) }}" loading="lazy" alt=""
                                class="size-12 shrink-0 rounded-xl object-cover">
                        @else
                            <span
                                class="grid size-12 shrink-0 place-items-center rounded-xl bg-lavender text-xs font-medium uppercase text-ink">
                                {{ $ext->isEmpty() ? 'file' : $ext }}
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $file->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                v{{ $v->number }} · {{ $v->humanSize() }} · {{ $v->uploader?->name ?? 'Someone' }} ·
                                {{ $v->created_at->diffForHumans() }}
                            </p>

                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($v->task)
                                    <span
                                        class="rounded-full bg-sky px-2 py-0.5 text-[11px] text-ink">{{ $project->key }}-{{ $v->task->number }}
                                        · {{ str($v->task->title)->limit(28) }}</span>
                                @else
                                    <span class="text-[11px] text-zinc-400">Project file</span>
                                @endif

                                @if ($v->changeRequest)
                                    <span class="rounded-full bg-lavender px-2 py-0.5 text-[11px] text-ink">via
                                        {{ $v->changeRequest->ref() }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="clock" wire:click="openHistory({{ $file->id }})"
                                aria-label="Version history" />
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('files.download', $v)"
                                aria-label="Download" />

                            @if ($this->canDelete)
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteFile({{ $file->id }})"
                                    wire:confirm="Move “{{ $file->name }}” to the recycle bin?" aria-label="Delete file" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <flux:modal name="file-history" variant="flyout" class="w-full max-w-lg">
        @if ($this->historyFile)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->historyFile->name }}</flux:heading>
                    <flux:subheading>Version history</flux:subheading>
                </div>

                <ol class="relative ms-2 border-s border-zinc-300/60 dark:border-white/15">
                    @foreach ($this->historyVersions as $version)
                        <li wire:key="version-{{ $version->id }}" class="relative pb-6 ps-6 last:pb-0">
                            <span
                                class="absolute -left-[7px] top-1.5 size-3 rounded-full {{ $loop->first ? 'bg-brand ring-4 ring-brand/30' : 'bg-zinc-300 dark:bg-white/30' }}"></span>

                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">
                                        v{{ $version->number }}
                                        @if ($loop->first)
                                            <span class="ms-1 rounded-full bg-brand px-2 py-0.5 text-[11px] text-ink">Current</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $version->uploader?->name ?? 'Someone' }} ·
                                        {{ $version->created_at->format('M j, Y · g:i A') }}
                                    </p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $version->humanSize() }} · {{ $version->shortHash() }}
                                    </p>

                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        @if ($version->task)
                                            <span
                                                class="rounded-full bg-sky px-2 py-0.5 text-[11px] text-ink">{{ $project->key }}-{{ $version->task->number }}
                                                · {{ str($version->task->title)->limit(24) }}</span>
                                        @endif
                                        @if ($version->changeRequest)
                                            <span class="rounded-full bg-lavender px-2 py-0.5 text-[11px] text-ink">via
                                                {{ $version->changeRequest->ref() }}</span>
                                        @endif
                                    </div>

                                    @if ($this->canUpload)
                                        <select wire:change="linkVersion({{ $version->id }}, $event.target.value)"
                                            class="mt-2 rounded-full border border-zinc-300/70 bg-white/70 px-3 py-1 text-xs dark:border-white/15 dark:bg-white/10"
                                            aria-label="Link this version to a task">
                                            <option value="">No task</option>
                                            @foreach ($this->tasks as $t)
                                                <option value="{{ $t->id }}" @selected($version->task_id === $t->id)>
                                                    {{ $project->key }}-{{ $t->number }} · {{ str($t->title)->limit(30) }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @endif
                                    @if ($version->note)
                                        <p class="mt-1 text-sm">{{ $version->note }}</p>
                                    @endif
                                </div>

                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray"
                                    :href="route('files.download', $version)" aria-label="Download this version" />
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </flux:modal>
</section>