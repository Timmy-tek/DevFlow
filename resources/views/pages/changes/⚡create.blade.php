<?php

use App\Livewire\Concerns\StagesChangeFiles;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Services\ChangeRequestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

use Livewire\Attributes\Url;

new #[Title('New change request')]
    class extends Component {
    use StagesChangeFiles;

    public string $slug;

    public string $title = '';
    public string $description = '';
    #[Url(as: 'task')]
    public string $taskId = '';
    public bool $syncTask = true;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        Gate::authorize('create', [ChangeRequest::class, $this->project]);
    }

    protected function stagingProject(): Project
    {
        return $this->project;
    }

    #[Computed]
    public function project(): Project
    {
        return Auth::user()->currentWorkspace->projects()->where('slug', $this->slug)->firstOrFail();
    }

    #[Computed]
    public function tasks()
    {
        return $this->project->tasks()->where('status', '!=', 'done')->orderBy('number')->get();
    }

    #[Computed]
    public function projectFiles()
    {
        return $this->project->files()->orderBy('name')->get();
    }

    public function save(): void
    {
        $project = $this->project;
        Gate::authorize('create', [ChangeRequest::class, $project]);

        $validated = $this->validate([
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'taskId' => [
                'nullable',
                Rule::exists('tasks', 'id')->where('project_id', $project->id)->whereNull('deleted_at'),
            ],
            'syncTask' => ['boolean'],
        ]);

        $this->resetErrorBag('staged');

        $service = app(ChangeRequestService::class);
        $problems = $service->problems($project, $this->staged);

        if ($problems) {
            $this->addError('staged', implode(' ', $problems));

            return;
        }

        $opaque = $service->opaqueNames($this->staged);

        if ($opaque && trim($validated['description'] ?? '') === '') {
            $this->addError('description', 'Describe what changed: ' . implode(', ', $opaque) . ' can\'t be compared line by line, so reviewers rely on your description.');

            return;
        }

        $cr = DB::transaction(function () use ($project, $validated, $service) {
            $cr = $project->changeRequests()->create([
                'title' => trim($validated['title']),
                'description' => $validated['description'] ?: null,
                'task_id' => filled($validated['taskId']) ? (int) $validated['taskId'] : null,
                'sync_task' => $validated['syncTask'],
                'created_by' => Auth::id(),
            ]);

            $service->pushRevision($cr, $this->staged, Auth::id());
            $service->opened($cr, Auth::id());

            return $cr;
        });

        $this->redirectRoute('changes.show', ['slug' => $project->slug, 'number' => $cr->number], navigate: true);
    }
}; ?>

@php $project = $this->project; @endphp

<section class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <a href="{{ route('changes.index', $project->slug) }}" wire:navigate
            class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Change requests</a>
        <h1 class="mt-1 text-4xl font-light tracking-tight sm:text-5xl">New change request</h1>
    </div>

    @if ($this->projectFiles->isEmpty())
        <x-card tone="butter" class="text-sm">
            A change request proposes changes to files that already exist, and this project has none yet.
            Upload them in the <a href="{{ route('projects.files', $project->slug) }}" wire:navigate
                class="font-medium underline">Files tab</a> first.
        </x-card>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <x-card class="flex flex-col gap-5">
                <flux:input wire:model="title" label="Title" placeholder="Update brand guidelines to v3" required />
                <flux:textarea wire:model="description" label="Description" rows="4" placeholder="What changed and why?" />

                <flux:field>
                    <flux:label>Linked task</flux:label>
                    <select wire:model="taskId"
                        class="w-full rounded-xl border border-zinc-300/70 bg-white/70 px-3 py-2 text-sm dark:border-white/15 dark:bg-white/10">
                        <option value="">No linked task</option>
                        @foreach ($this->tasks as $task)
                            <option value="{{ $task->id }}">{{ $project->key }}-{{ $task->number }} · {{ $task->title }}
                            </option>
                        @endforeach
                    </select>
                    <flux:error name="taskId" />
                </flux:field>

                <flux:checkbox wire:model="syncTask" label="Keep the linked task in sync (Review while this is open)" />
            </x-card>

            <x-card class="flex flex-col gap-4">
                <div>
                    <h2 class="text-lg font-medium">Changed files</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Drop the updated versions. Names that match a
                        project file are linked automatically.</p>
                </div>

                <x-dropzone wire:model="uploads" hint="Up to 10 MB each" />

                <x-staged-files :staged="$staged" :files="$this->projectFiles" />

                @error('staged')
                <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                @error('uploads.*')
                <p class="text-sm text-red-500">{{ $message }}</p> @enderror
            </x-card>

            <div class="flex justify-end gap-2">
                <flux:button :href="route('changes.index', $project->slug)" wire:navigate variant="ghost">Cancel
                </flux:button>
                <flux:button type="submit" variant="primary" class="!rounded-full">Open change request</flux:button>
            </div>
        </form>
    @endif
</section>