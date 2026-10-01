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

new #[Title('Files')] class extends Component {
    use WithFileUploads;

    public string $slug;

    public $uploads = [];

    public ?int $historyFileId = null;

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
    public function files()
    {
        return $this->project->files()->with('latestVersion.uploader')->orderBy('name')->get();
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
            ? $this->historyFile->versions()->with('uploader')->orderByDesc('number')->get()
            : collect();
    }

    // Runs as soon as files finish uploading to Livewire's temporary storage.
    public function updatedUploads(): void
    {
        $project = $this->project;
        Gate::authorize('create', [ProjectFile::class, $project]);

        $this->resetErrorBag('uploads');

        $saved = 0;
        $problems = [];

        foreach (Arr::wrap($this->uploads) as $upload) {
            $name = $upload->getClientOriginalName();

            if ($upload->getSize() > 10 * 1024 * 1024) {
                $problems[] = "{$name} is over 10 MB.";
                continue;
            }

            // Existing files can only change through a change request.
            $exists = $project->files()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();

            if ($exists) {
                $problems[] = "{$name} already exists. Changes to existing files go through a change request.";
                continue;
            }

            DB::transaction(function () use ($project, $upload, $name) {
                $file = $project->files()->create([
                    'name' => mb_substr($name, 0, 255),
                    'created_by' => Auth::id(),
                ]);

                $file->addVersion($upload, Auth::id());

                ActivityLog::create([
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->id,
                    'user_id' => Auth::id(),
                    'action' => 'file.uploaded',
                    'properties' => ['name' => $file->name, 'version' => 1],
                ]);
            });

            $saved++;
        }

        $this->uploads = [];
        unset($this->files);

        if ($saved > 0) {
            Flux::toast(variant: 'success', text: $saved === 1 ? 'File uploaded.' : "{$saved} files uploaded.");
        }

        if ($problems) {
            $this->addError('uploads', implode(' ', $problems));
        }
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
        <a href="{{ route('projects.index') }}" wire:navigate class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Projects</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">{{ $project->name }}</h1>
    </div>

    <x-project-tabs :project="$project" active="files" />

    @if ($this->canUpload)
        <div
            class="relative"
            x-data="{ over: false, uploading: false, progress: 0 }"
            x-on:livewire-upload-start="uploading = true; progress = 0"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-error="uploading = false"
        >
            <div
                class="glass flex flex-col items-center gap-2 rounded-card border-2 border-dashed px-6 py-10 text-center transition"
                :class="over ? 'border-ink dark:border-brand' : 'border-zinc-300/70 dark:border-white/20'"
            >
                <flux:icon name="arrow-up-tray" class="size-7 text-zinc-500 dark:text-zinc-400" />
                <span class="text-xl font-light">Drop files here or click to browse</span>
                <span class="text-xs text-zinc-500 dark:text-zinc-400">Up to 10 MB each. New files only: changes to existing files go through a change request.</span>

                <div x-show="uploading" style="display: none" class="mt-2 h-1.5 w-full max-w-xs rounded-full bg-black/10">
                    <div class="h-1.5 rounded-full bg-ink dark:bg-brand" :style="`width: ${progress}%`"></div>
                </div>
            </div>

            <input
                type="file"
                multiple
                wire:model="uploads"
                class="absolute inset-0 size-full cursor-pointer opacity-0"
                x-on:dragover="over = true"
                x-on:dragleave="over = false"
                x-on:drop="over = false"
            >
        </div>

        @error('uploads') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
        @error('uploads.*') <p class="text-sm text-red-500">{{ $message }}</p> @enderror
    @endif

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

                    <li wire:key="file-{{ $file->id }}" class="flex items-center gap-4 rounded-2xl bg-white/70 px-4 py-3 dark:bg-white/10">
                        @if ($v->isImage())
                            <img src="{{ route('files.preview', $v) }}" loading="lazy" alt="" class="size-12 shrink-0 rounded-xl object-cover">
                        @else
                            <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-lavender text-xs font-medium uppercase text-ink">
                                {{ $ext->isEmpty() ? 'file' : $ext }}
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $file->name }}</p>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">
                                v{{ $v->number }} · {{ $v->humanSize() }} · {{ $v->uploader?->name ?? 'Someone' }} · {{ $v->created_at->diffForHumans() }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="sm" variant="ghost" icon="clock" wire:click="openHistory({{ $file->id }})" aria-label="Version history" />
                            <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('files.download', $v)" aria-label="Download" />

                            @if ($this->canDelete)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="deleteFile({{ $file->id }})"
                                    wire:confirm="Move “{{ $file->name }}” to the recycle bin?"
                                    aria-label="Delete file"
                                />
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
                            <span class="absolute -left-[7px] top-1.5 size-3 rounded-full {{ $loop->first ? 'bg-brand ring-4 ring-brand/30' : 'bg-zinc-300 dark:bg-white/30' }}"></span>

                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium">
                                        v{{ $version->number }}
                                        @if ($loop->first)
                                            <span class="ms-1 rounded-full bg-brand px-2 py-0.5 text-[11px] text-ink">Current</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $version->uploader?->name ?? 'Someone' }} · {{ $version->created_at->format('M j, Y · g:i A') }}
                                    </p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $version->humanSize() }} · {{ $version->shortHash() }}
                                    </p>
                                    @if ($version->note)
                                        <p class="mt-1 text-sm">{{ $version->note }}</p>
                                    @endif
                                </div>

                                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('files.download', $version)" aria-label="Download this version" />
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif
    </flux:modal>
</section>