<?php

use App\Enums\WorkspaceRole;
use App\Livewire\Concerns\StagesChangeFiles;
use App\Models\ChangeRequest;
use App\Models\CrFileView;
use App\Models\FileVersion;
use App\Models\Project;
use App\Services\ChangeRequestService;
use App\Support\ReviewSummary;
use App\Support\TextDiff;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Change request')]
    class extends Component {
    use StagesChangeFiles;

    public string $slug;
    public int $number;

    #[Url]
    public string $tab = 'conversation';

    public string $commentBody = '';
    public string $revisionNote = '';
    public string $reviewBody = '';

    public function mount(string $slug, int $number): void
    {
        $this->slug = $slug;
        $this->number = $number;

        Gate::authorize('view', $this->cr);
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

    // Looked up through the current project, so a number from another workspace 404s.
    #[Computed]
    public function cr(): ChangeRequest
    {
        return $this->project->changeRequests()
            ->where('number', $this->number)
            ->with(['author', 'merger', 'task', 'reviews', 'latestRevision'])
            ->firstOrFail();
    }

    #[Computed]
    public function summary(): ReviewSummary
    {
        return $this->cr->reviewSummary();
    }

    #[Computed]
    public function canRevise(): bool
    {
        return Auth::user()->can('revise', $this->cr);
    }

    #[Computed]
    public function canComment(): bool
    {
        return Auth::user()->can('comment', $this->cr);
    }

    #[Computed]
    public function canReview(): bool
    {
        return Auth::user()->can('review', $this->cr);
    }

    #[Computed]
    public function canMerge(): bool
    {
        return Auth::user()->can('merge', $this->cr);
    }

    #[Computed]
    public function revisions()
    {
        return $this->cr->revisions()->with(['author', 'files.file'])->orderBy('number')->get();
    }

    #[Computed]
    public function fileCount(): int
    {
        return $this->cr->latestFiles()->count();
    }

    #[Computed]
    public function projectFiles()
    {
        return $this->project->files()->orderBy('name')->get();
    }

    // Workspace members who can review, minus the author and anyone already asked.
    #[Computed]
    public function eligibleReviewers()
    {
        $cr = $this->cr;
        $taken = $cr->reviewers()->pluck('users.id')->map(fn($id) => (int) $id)->all();

        return $this->project->workspace->members()->orderBy('name')->get()
            ->filter(fn($member) => $member->id !== $cr->created_by
                && !in_array($member->id, $taken, true)
                && WorkspaceRole::from($member->pivot->role)->canContribute())
            ->values();
    }

    // A "Viewed" tick only counts while the file is unchanged since you ticked it.
    #[Computed]
    public function viewedFileIds(): array
    {
        $latest = $this->cr->latestFiles();

        return CrFileView::where('change_request_id', $this->cr->id)
            ->where('user_id', Auth::id())
            ->get()
            ->filter(fn($view) => $latest->get($view->project_file_id)?->id === $view->cr_revision_file_id)
            ->pluck('project_file_id')
            ->all();
    }

    #[Computed]
    public function mergedVersions()
    {
        if ($this->cr->status !== ChangeRequest::MERGED) {
            return collect();
        }

        return FileVersion::where('change_request_id', $this->cr->id)->with('file')->orderBy('id')->get();
    }

    // Each changed file compared against the version it was based on.
    #[Computed]
    public function diffs(): array
    {
        $disk = Storage::disk('local');
        $out = [];

        foreach ($this->cr->latestFiles() as $rf) {
            $base = $rf->baseVersion;
            $entry = ['rf' => $rf, 'base' => $base, 'kind' => 'binary', 'rows' => [], 'added' => 0, 'removed' => 0];

            if ($rf->isImage() && $base->isImage()) {
                $entry['kind'] = 'image';
            } elseif (
                TextDiff::looksLikeText($rf->original_name, $rf->mime)
                && $rf->size <= 512 * 1024
                && $base->size <= 512 * 1024
            ) {
                $old = $disk->get($base->path);
                $new = $disk->get($rf->path);

                if (is_string($old) && is_string($new) && !str_contains($old . $new, "\0")) {
                    $result = TextDiff::compare($old, $new);

                    if ($result !== null) {
                        $entry = [...$entry, ...$result, 'kind' => 'text'];
                    }
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    // One chronological stream of everything that happened.
    #[Computed]
    public function timeline(): array
    {
        $cr = $this->cr;
        $items = [['type' => 'opened', 'at' => $cr->created_at, 'user' => $cr->author]];

        foreach ($this->revisions as $revision) {
            $items[] = ['type' => 'revision', 'at' => $revision->created_at, 'user' => $revision->author, 'revision' => $revision];
        }

        foreach ($cr->comments()->with('author')->get() as $comment) {
            $items[] = ['type' => 'comment', 'at' => $comment->created_at, 'user' => $comment->author, 'comment' => $comment];
        }

        foreach ($cr->reviews()->with(['reviewer', 'revision'])->get() as $review) {
            $items[] = ['type' => 'review', 'at' => $review->created_at, 'user' => $review->reviewer, 'review' => $review];
        }

        if ($cr->merged_at) {
            $items[] = ['type' => 'merged', 'at' => $cr->merged_at, 'user' => $cr->merger];
        }

        if ($cr->closed_at) {
            $items[] = ['type' => 'closed', 'at' => $cr->closed_at, 'user' => null];
        }

        usort($items, fn($a, $b) => $a['at'] <=> $b['at']);

        return $items;
    }

    protected function forgetCaches(): void
    {
        unset(
            $this->cr,
            $this->summary,
            $this->revisions,
            $this->fileCount,
            $this->diffs,
            $this->timeline,
            $this->eligibleReviewers,
            $this->viewedFileIds,
            $this->mergedVersions,
        );
    }

    public function addComment(): void
    {
        $cr = $this->cr;
        Gate::authorize('comment', $cr);

        $validated = $this->validate([
            'commentBody' => ['required', 'string', 'max:2000'],
        ]);

        $cr->comments()->create([
            'body' => trim($validated['commentBody']),
            'user_id' => Auth::id(),
        ]);

        app(ChangeRequestService::class)->log($cr, 'cr.comment', Auth::id());

        $this->reset('commentBody');
        unset($this->timeline);
    }

    public function pushRevision(): void
    {
        $cr = $this->cr;
        Gate::authorize('revise', $cr);

        $this->validate(['revisionNote' => ['nullable', 'string', 'max:1000']]);
        $this->resetErrorBag('staged');

        $service = app(ChangeRequestService::class);
        $problems = $service->problems($this->project, $this->staged);

        if ($problems) {
            $this->addError('staged', implode(' ', $problems));

            return;
        }

        $service->pushRevision($cr, $this->staged, Auth::id(), trim($this->revisionNote) ?: null);

        $this->staged = [];
        $this->revisionNote = '';

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Revision pushed. Earlier approvals are now stale.');
    }

    public function submitReview(string $state): void
    {
        $cr = $this->cr;
        Gate::authorize('review', $cr);

        abort_unless(in_array($state, ['approved', 'changes_requested'], true), 422);

        $this->validate([
            'reviewBody' => $state === 'changes_requested'
                ? ['required', 'string', 'min:3', 'max:2000']
                : ['nullable', 'string', 'max:2000'],
        ]);

        $revision = $cr->latestRevision;
        abort_unless($revision, 422);

        // The review is pinned to the revision it was written against.
        $cr->reviews()->create([
            'user_id' => Auth::id(),
            'cr_revision_id' => $revision->id,
            'state' => $state,
            'body' => trim($this->reviewBody) ?: null,
        ]);

        app(ChangeRequestService::class)->log($cr, $state === 'approved' ? 'cr.approved' : 'cr.changes_requested', Auth::id());

        $this->reset('reviewBody');
        $this->forgetCaches();
        Flux::toast(variant: 'success', text: $state === 'approved' ? 'Approved.' : 'Changes requested.');
    }

    public function addReviewer(string $userId): void
    {
        $cr = $this->cr;
        Gate::authorize('revise', $cr);

        if ($userId === '') {
            return;
        }

        $user = $this->eligibleReviewers->firstWhere('id', (int) $userId);
        abort_unless($user, 422);

        $cr->reviewers()->syncWithoutDetaching([$user->id]);

        $this->forgetCaches();
    }

    public function removeReviewer(int $userId): void
    {
        $cr = $this->cr;
        Gate::authorize('revise', $cr);

        $cr->reviewers()->detach($userId);

        $this->forgetCaches();
    }

    public function toggleViewed(int $fileId): void
    {
        $cr = $this->cr;
        Gate::authorize('view', $cr);

        $rf = $cr->latestFiles()->get($fileId);
        abort_unless($rf, 404);

        $existing = CrFileView::where('change_request_id', $cr->id)
            ->where('user_id', Auth::id())
            ->where('project_file_id', $fileId)
            ->first();

        if ($existing && $existing->cr_revision_file_id === $rf->id) {
            $existing->delete();
        } else {
            CrFileView::updateOrCreate(
                ['change_request_id' => $cr->id, 'user_id' => Auth::id(), 'project_file_id' => $fileId],
                ['cr_revision_file_id' => $rf->id],
            );
        }

        unset($this->viewedFileIds);
    }

    public function merge(): void
    {
        $cr = $this->cr;
        Gate::authorize('merge', $cr);

        $this->resetErrorBag('merge');

        try {
            app(ChangeRequestService::class)->merge($cr, Auth::id());
        } catch (\RuntimeException $e) {
            $this->addError('merge', $e->getMessage());
            $this->forgetCaches();

            return;
        }

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Merged. The new file versions are live.');
    }

    public function close(): void
    {
        $cr = $this->cr;
        Gate::authorize('close', $cr);

        app(ChangeRequestService::class)->close($cr, Auth::id());

        $this->forgetCaches();
        Flux::toast(variant: 'success', text: 'Change request closed.');
    }
}; ?>

@php
    $cr = $this->cr;
    $project = $this->project;
    $summary = $this->summary;
    $tab = in_array($tab, ['conversation', 'files', 'revisions'], true) ? $tab : 'conversation';
    [$badgeLabel, $badgeClass] = $cr->badge();

    $stateLabels = ['approved' => 'Approved', 'changes_requested' => 'Changes requested', 'stale' => 'Stale approval', 'pending' => 'Pending'];
    $stateIcons = ['approved' => 'check', 'changes_requested' => 'exclamation-circle', 'stale' => 'arrow-path', 'pending' => 'clock'];
    $segmentClasses = [
        'approved' => 'bg-mint text-ink',
        'changes_requested' => 'bg-rose text-ink',
        'pending' => 'bg-butter text-ink',
        'stale' => 'border border-dashed border-zinc-400 text-zinc-600 dark:text-zinc-300',
    ];
    $chipClasses = [
        'approved' => 'bg-mint text-ink',
        'changes_requested' => 'bg-rose text-ink',
        'pending' => 'bg-butter text-ink',
        'stale' => 'border border-dashed border-white/40 text-white/70',
    ];
@endphp

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <a href="{{ route('changes.index', $project->slug) }}" wire:navigate
                class="text-sm text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white">← Change
                requests</a>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $cr->ref() }} · opened by {{ $cr->author?->name ?? 'someone' }}
                {{ $cr->created_at->diffForHumans() }}
            </p>
            <h1 class="text-3xl font-light tracking-tight sm:text-4xl">{{ $cr->title }}</h1>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-xs">
            @if ($cr->task)
                <a href="{{ route('projects.board', $project->slug) }}" wire:navigate
                    class="rounded-full bg-sky px-3 py-1 text-ink">
                    {{ $project->key }}-{{ $cr->task->number }} {{ $cr->task->title }}
                </a>
            @endif
            <span class="rounded-full px-3 py-1 {{ $badgeClass }}">{{ $badgeLabel }}</span>
        </div>
    </div>

    {{-- Review bar --}}
    @if ($cr->isOpen())
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
                <div class="flex min-w-0 flex-1 flex-wrap gap-1.5">
                    @forelse ($summary->reviewers as $r)
                        <div wire:key="seg-{{ $r['user_id'] }}"
                            class="flex min-w-[10rem] flex-1 items-center gap-2 whitespace-nowrap rounded-full px-4 py-2 text-sm {{ $segmentClasses[$r['state']] }}">
                            <flux:icon :name="$stateIcons[$r['state']]" class="size-4 shrink-0" />
                            <span class="truncate">{{ $stateLabels[$r['state']] }} · {{ $r['name'] }}</span>
                        </div>
                    @empty
                        <div
                            class="flex flex-1 items-center gap-2 rounded-full border border-dashed border-zinc-400 px-4 py-2 text-sm text-zinc-600 dark:text-zinc-300">
                            <flux:icon name="user-plus" class="size-4 shrink-0" />
                            No reviewers yet · {{ $summary->required() }} approval needed
                        </div>
                    @endforelse
                </div>

                @if ($this->canMerge)
                    <button type="button" wire:click="merge" @disabled(!$summary->ready())
                        class="rounded-full bg-ink px-6 py-2 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-40 dark:bg-brand dark:text-ink">
                        Merge
                    </button>
                @endif
            </div>

            @if ($this->canMerge && $summary->blockers() !== [])
                <p class="px-2 text-xs text-zinc-500 dark:text-zinc-400">{{ implode(' · ', $summary->blockers()) }}</p>
            @endif
        </div>
    @elseif ($cr->status === 'merged')
        <div class="flex items-center gap-2 rounded-full bg-lavender px-5 py-2.5 text-sm text-ink">
            <flux:icon name="check-circle" class="size-4" />
            Merged by {{ $cr->merger?->name ?? 'someone' }} {{ $cr->merged_at?->diffForHumans() }}
        </div>
    @else
        <div
            class="flex items-center gap-2 rounded-full bg-zinc-200 px-5 py-2.5 text-sm text-ink dark:bg-white/15 dark:text-white">
            <flux:icon name="x-circle" class="size-4" />
            Closed without merging {{ $cr->closed_at?->diffForHumans() }}
        </div>
    @endif

    {{-- Folder-style tabs --}}
    <div class="flex items-end gap-1 px-2">
        @foreach (['conversation' => 'Conversation', 'files' => 'Files changed', 'revisions' => 'Revisions'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                'relative -mb-px rounded-t-2xl px-5 py-2.5 text-sm font-medium transition',
                'glass !border-b-transparent' => $tab === $key,
                'text-zinc-500 hover:text-ink dark:text-zinc-400 dark:hover:text-white' => $tab !== $key,
            ])>
                {{ $label }}
                @if ($key === 'files')
                    <span class="ms-1.5 rounded-full bg-black/10 px-1.5 text-xs dark:bg-white/10">{{ $this->fileCount }}</span>
                @elseif ($key === 'revisions')
                    <span
                        class="ms-1.5 rounded-full bg-black/10 px-1.5 text-xs dark:bg-white/10">{{ $this->revisions->count() }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ───────── Conversation ───────── --}}
    @if ($tab === 'conversation')
        <div class="grid gap-4 lg:grid-cols-12">
            <div class="flex flex-col gap-4 lg:col-span-8">
                @if ($cr->description)
                    <x-card>
                        <p class="whitespace-pre-line text-sm leading-relaxed">{{ $cr->description }}</p>
                    </x-card>
                @endif

                <x-card>
                    <ol class="relative ms-2 border-s border-zinc-300/60 dark:border-white/15">
                        @foreach ($this->timeline as $item)
                            <li wire:key="tl-{{ $loop->index }}" class="relative pb-6 ps-6 last:pb-0">
                                <span
                                    class="absolute -left-[7px] top-1.5 size-3 rounded-full bg-brand ring-4 ring-white/70 dark:ring-white/10"></span>

                                @if ($item['type'] === 'opened')
                                    <p class="text-sm"><span class="font-medium">{{ $item['user']?->name ?? 'Someone' }}</span>
                                        opened this change request</p>
                                @elseif ($item['type'] === 'revision')
                                    @php $rev = $item['revision']; @endphp
                                    <p class="text-sm"><span class="font-medium">{{ $item['user']?->name ?? 'Someone' }}</span>
                                        pushed <span class="font-medium">r{{ $rev->number }}</span></p>
                                    @if ($rev->note)
                                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $rev->note }}</p>
                                    @endif
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($rev->files as $f)
                                            <span
                                                class="rounded-full bg-white/70 px-2.5 py-0.5 text-xs dark:bg-white/10">{{ $f->file?->name ?? $f->original_name }}
                                                · {{ $f->humanSize() }}</span>
                                        @endforeach
                                    </div>
                                @elseif ($item['type'] === 'review')
                                    @php $review = $item['review']; @endphp
                                    <p class="text-sm">
                                        <span class="font-medium">{{ $item['user']?->name ?? 'Someone' }}</span>
                                        {{ $review->state === 'approved' ? 'approved' : 'requested changes on' }}
                                        <span class="font-medium">r{{ $review->revision?->number }}</span>
                                    </p>
                                    @if ($review->body)
                                        <p class="mt-1 whitespace-pre-line rounded-2xl bg-white/70 px-4 py-3 text-sm dark:bg-white/10">
                                            {{ $review->body }}</p>
                                    @endif
                                @elseif ($item['type'] === 'comment')
                                    <div class="flex gap-3">
                                        <flux:avatar :name="$item['user']?->name ?? 'Deleted user'"
                                            :initials="$item['user']?->initials() ?? '?'" size="xs" circle />
                                        <div class="min-w-0 flex-1 rounded-2xl bg-white/70 px-4 py-3 dark:bg-white/10">
                                            <p class="text-sm font-medium">{{ $item['user']?->name ?? 'Deleted user' }}</p>
                                            <p class="mt-1 whitespace-pre-line text-sm">{{ $item['comment']->body }}</p>
                                        </div>
                                    </div>
                                @elseif ($item['type'] === 'merged')
                                    <p class="text-sm"><span class="font-medium">{{ $item['user']?->name ?? 'Someone' }}</span>
                                        merged this change request</p>
                                @elseif ($item['type'] === 'closed')
                                    <p class="text-sm">This change request was closed without merging</p>
                                @endif

                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['at']->diffForHumans() }}</p>
                            </li>
                        @endforeach
                    </ol>

                    @if ($this->canComment)
                        <form wire:submit="addComment"
                            class="mt-6 flex flex-col gap-2 border-t border-zinc-200/70 pt-4 dark:border-white/10">
                            <flux:textarea wire:model="commentBody" rows="2" placeholder="Write a comment…" />
                            <div class="flex justify-end">
                                <flux:button type="submit" size="sm" variant="primary">Comment</flux:button>
                            </div>
                        </form>
                    @endif
                </x-card>
            </div>

            <div class="flex flex-col gap-4 lg:col-span-4">
                @if ($cr->isOpen())
                    <x-card tone="dark" class="flex flex-col gap-4">
                        <div>
                            <h2 class="text-lg font-medium">Review</h2>
                            <p class="text-xs text-white/60">{{ $summary->approvals }} of {{ $summary->required() }} required
                                approvals</p>
                        </div>

                        @if ($summary->reviewers !== [])
                            <div class="flex flex-col gap-2">
                                @foreach ($summary->reviewers as $r)
                                    <div wire:key="rv-{{ $r['user_id'] }}" class="flex items-center gap-2 text-sm">
                                        <span
                                            class="grid size-6 shrink-0 place-items-center rounded-full bg-white/20 text-[11px]">{{ str($r['name'])->substr(0, 2)->upper() }}</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $r['name'] }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] {{ $chipClasses[$r['state']] }}">{{ $stateLabels[$r['state']] }}</span>
                                        @if ($this->canRevise && $r['requested'])
                                            <button type="button" wire:click="removeReviewer({{ $r['user_id'] }})"
                                                class="text-white/40 hover:text-white" aria-label="Remove reviewer">
                                                <flux:icon name="x-mark" class="size-4" />
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($this->canRevise && $this->eligibleReviewers->isNotEmpty())
                            <select wire:change="addReviewer($event.target.value)"
                                class="rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs text-white">
                                <option value="" class="text-ink">Request a reviewer…</option>
                                @foreach ($this->eligibleReviewers as $member)
                                    <option value="{{ $member->id }}" class="text-ink">{{ $member->name }}</option>
                                @endforeach
                            </select>
                        @endif

                        @if ($this->canReview)
                            <div class="flex flex-col gap-2">
                                <textarea wire:model="reviewBody" rows="2"
                                    placeholder="Leave a note (required when requesting changes)"
                                    class="w-full rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white placeholder-white/40 outline-none focus:ring-2 focus:ring-brand"></textarea>
                                @error('reviewBody')
                                <p class="text-xs text-rose">{{ $message }}</p> @enderror

                                <div class="flex gap-2">
                                    <button type="button" wire:click="submitReview('approved')"
                                        class="flex-1 rounded-full bg-brand px-4 py-2 text-sm font-medium text-ink">Approve</button>
                                    <button type="button" wire:click="submitReview('changes_requested')"
                                        class="flex-1 rounded-full border border-white/25 px-4 py-2 text-sm transition hover:bg-white/10">Request
                                        changes</button>
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-col gap-1.5 border-t border-white/15 pt-3 text-xs">
                            @php
                                $checks = [
                                    [$summary->approvals >= $summary->required(), '1 approval on the latest revision'],
                                    [$summary->changesRequestedBy() === [], 'No changes requested'],
                                    [$summary->outOfDate === [], 'Files are up to date'],
                                ];
                            @endphp

                            @foreach ($checks as [$ok, $text])
                                <div class="flex items-center gap-2 {{ $ok ? '' : 'text-white/60' }}">
                                    <flux:icon :name="$ok ? 'check' : 'minus-circle'"
                                        class="size-4 {{ $ok ? 'text-brand' : '' }}" />
                                    {{ $text }}
                                </div>
                            @endforeach

                            <div class="flex items-center gap-2 text-white/60">
                                <flux:icon name="eye" class="size-4" />
                                {{ count($this->viewedFileIds) }} of {{ $this->fileCount }} files viewed by you
                            </div>
                        </div>

                        @if ($this->canMerge)
                            <button type="button" wire:click="merge" @disabled(!$summary->ready())
                                class="rounded-full bg-brand px-4 py-2.5 text-sm font-medium text-ink transition disabled:cursor-not-allowed disabled:opacity-40">
                                Merge into the project
                            </button>
                        @endif

                        @error('merge')
                        <p class="text-xs text-rose">{{ $message }}</p> @enderror

                        @if ($this->canRevise)
                            <button type="button" wire:click="close" wire:confirm="Close this change request without merging?"
                                class="rounded-full border border-white/20 px-4 py-2 text-sm transition hover:bg-white/10">
                                Close without merging
                            </button>
                        @endif
                    </x-card>
                @elseif ($cr->status === 'merged')
                    <x-card tone="dark" class="flex flex-col gap-4">
                        <div>
                            <h2 class="text-lg font-medium">Merged</h2>
                            <p class="text-xs text-white/60">New versions created by this change request</p>
                        </div>

                        <ul class="flex flex-col gap-2 text-sm">
                            @foreach ($this->mergedVersions as $version)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="truncate">{{ $version->file?->name }}</span>
                                    <span
                                        class="shrink-0 rounded-full bg-white/15 px-2 py-0.5 text-xs">v{{ $version->number }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ route('projects.files', $project->slug) }}" wire:navigate
                            class="rounded-full bg-brand px-4 py-2.5 text-center text-sm font-medium text-ink">View in Files</a>
                    </x-card>
                @else
                    <x-card tone="dark" class="flex flex-col gap-2">
                        <h2 class="text-lg font-medium">Closed</h2>
                        <p class="text-sm text-white/60">This change request was closed without merging. Nothing in the project
                            changed.</p>
                    </x-card>
                @endif

                @if ($cr->task)
                    <x-card>
                        <h3 class="text-sm text-zinc-500 dark:text-zinc-400">Linked task</h3>
                        <a href="{{ route('projects.board', $project->slug) }}" wire:navigate
                            class="mt-1 block font-medium hover:underline">
                            {{ $project->key }}-{{ $cr->task->number }} · {{ $cr->task->title }}
                        </a>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Now:
                            {{ $cr->task->status->label() }}{{ $cr->sync_task ? ' · moves to Done when merged' : ' · not synced' }}
                        </p>
                    </x-card>
                @endif
            </div>
        </div>
    @endif

    {{-- ───────── Files changed ───────── --}}
    @if ($tab === 'files')
        <div class="flex flex-col gap-4">
            <p class="px-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ count($this->viewedFileIds) }} of {{ $this->fileCount }} files viewed
            </p>

            @forelse ($this->diffs as $d)
                @php
                    $rf = $d['rf'];
                    $base = $d['base'];
                    $isViewed = in_array($rf->project_file_id, $this->viewedFileIds, true);
                @endphp

                <x-card x-data="{ viewed: {{ $isViewed ? 'true' : 'false' }} }" wire:key="diff-{{ $rf->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $rf->file?->name ?? $rf->original_name }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">v{{ $base->number }} → proposed in
                                r{{ $rf->revision->number }}</p>
                        </div>

                        <div class="flex items-center gap-4 text-xs">
                            @if ($d['kind'] === 'text')
                                <span class="text-emerald-600 dark:text-emerald-400">+{{ $d['added'] }}</span>
                                <span class="text-rose-600 dark:text-rose-400">−{{ $d['removed'] }}</span>
                            @endif
                            <label class="flex cursor-pointer items-center gap-1.5 text-zinc-600 dark:text-zinc-300">
                                <input type="checkbox" x-model="viewed"
                                    x-on:change="$wire.toggleViewed({{ $rf->project_file_id }})"> Viewed
                            </label>
                        </div>
                    </div>

                    <div x-show="! viewed" class="mt-4">
                        @if ($d['kind'] === 'text')
                            <div
                                class="overflow-x-auto rounded-xl border border-zinc-200/70 font-mono text-xs dark:border-white/10">
                                @forelse ($d['rows'] as $row)
                                    @if ($row['type'] === 'gap')
                                        <div class="bg-zinc-900/5 px-3 py-1 text-center text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                                            {{ $row['text'] }}</div>
                                    @else
                                        <div @class([
                                            'grid grid-cols-[3rem_3rem_1.25rem_1fr]',
                                            'bg-emerald-100/70 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-200' => $row['type'] === 'add',
                                            'bg-rose-100/80 text-rose-900 dark:bg-rose-500/15 dark:text-rose-200' => $row['type'] === 'del',
                                        ])>
                                            <span class="select-none px-2 text-end opacity-50">{{ $row['old'] }}</span>
                                            <span class="select-none px-2 text-end opacity-50">{{ $row['new'] }}</span>
                                            <span
                                                class="select-none">{{ $row['type'] === 'add' ? '+' : ($row['type'] === 'del' ? '−' : ' ') }}</span>
                                            <span class="whitespace-pre-wrap break-all pe-3">{{ $row['text'] }}</span>
                                        </div>
                                    @endif
                                @empty
                                    <div class="px-3 py-4 text-center text-zinc-500 dark:text-zinc-400">No textual changes (whitespace
                                        or line endings only).</div>
                                @endforelse
                            </div>
                        @elseif ($d['kind'] === 'image')
                            <div x-data="{ pos: 50 }" class="space-y-2">
                                <div class="relative h-80 overflow-hidden rounded-xl bg-zinc-900/5 dark:bg-white/5">
                                    <img src="{{ route('cr-files.preview', $rf) }}" alt="Proposed version"
                                        class="absolute inset-0 size-full object-contain">
                                    <img src="{{ route('files.preview', $base) }}" alt="Current version"
                                        class="absolute inset-0 size-full object-contain"
                                        :style="`clip-path: inset(0 ${100 - pos}% 0 0)`">
                                    <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-brand shadow"
                                        :style="`left: ${pos}%`"></div>
                                </div>
                                <input type="range" min="0" max="100" x-model="pos" class="w-full accent-ink dark:accent-brand"
                                    aria-label="Compare current and proposed">
                                <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                                    <span>Current · v{{ $base->number }}</span>
                                    <span>Proposed · r{{ $rf->revision->number }}</span>
                                </div>
                            </div>
                        @else
                            <div class="grid gap-3 text-sm sm:grid-cols-2">
                                <div class="rounded-xl bg-zinc-900/5 p-3 dark:bg-white/5">
                                    <p class="font-medium">Current · v{{ $base->number }}</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $base->humanSize() }} ·
                                        {{ $base->shortHash() }}</p>
                                    <a href="{{ route('files.download', $base) }}"
                                        class="mt-2 inline-block text-xs underline">Download</a>
                                </div>
                                <div class="rounded-xl bg-zinc-900/5 p-3 dark:bg-white/5">
                                    <p class="font-medium">Proposed · r{{ $rf->revision->number }}</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $rf->humanSize() }} ·
                                        {{ $rf->shortHash() }}</p>
                                    <a href="{{ route('cr-files.download', $rf) }}"
                                        class="mt-2 inline-block text-xs underline">Download</a>
                                </div>
                            </div>
                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">No inline diff available for this file (binary
                                or too large).</p>
                        @endif
                    </div>
                </x-card>
            @empty
                <x-card class="py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">No files in this change
                    request.</x-card>
            @endforelse
        </div>
    @endif

    {{-- ───────── Revisions ───────── --}}
    @if ($tab === 'revisions')
        <div class="flex flex-col gap-4">
            @if ($this->canRevise)
                <x-card class="flex flex-col gap-4">
                    <div>
                        <h2 class="text-lg font-medium">Push a new revision</h2>
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">Upload updated files. Earlier revisions are kept,
                            and the newest version of each file is what gets reviewed. Earlier approvals become stale.</p>
                    </div>

                    <x-dropzone wire:model="uploads" hint="Up to 10 MB each" />
                    <x-staged-files :staged="$staged" :files="$this->projectFiles" />

                    @error('staged')
                    <p class="text-sm text-red-500">{{ $message }}</p> @enderror
                    @error('uploads.*')
                    <p class="text-sm text-red-500">{{ $message }}</p> @enderror

                    @if (count($staged))
                        <flux:textarea wire:model="revisionNote" label="What changed in this revision?" rows="2" />
                        <div class="flex justify-end">
                            <flux:button wire:click="pushRevision" variant="primary" class="!rounded-full">Push revision
                            </flux:button>
                        </div>
                    @endif
                </x-card>
            @endif

            @foreach ($this->revisions->reverse() as $rev)
                <x-card wire:key="rev-{{ $rev->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-medium">r{{ $rev->number }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $rev->author?->name ?? 'Someone' }} ·
                                {{ $rev->created_at->format('M j, Y · g:i A') }}</p>
                        </div>
                    </div>

                    @if ($rev->note)
                        <p class="mt-2 text-sm">{{ $rev->note }}</p>
                    @endif

                    <ul class="mt-3 grid gap-2">
                        @foreach ($rev->files as $f)
                            <li
                                class="flex items-center justify-between gap-3 rounded-2xl bg-white/70 px-4 py-2.5 text-sm dark:bg-white/10">
                                <span class="min-w-0 truncate">{{ $f->file?->name ?? $f->original_name }}</span>
                                <span class="flex shrink-0 items-center gap-3 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $f->humanSize() }} · {{ $f->shortHash() }}
                                    <a href="{{ route('cr-files.download', $f) }}" aria-label="Download"
                                        class="text-ink dark:text-white">
                                        <flux:icon name="arrow-down-tray" class="size-4" />
                                    </a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endforeach
        </div>
    @endif
</section>