@props(['thread', 'canDiscuss' => false, 'canResolve' => false])

<div x-data="{ open: false, reply: '' }" wire:key="thread-{{ $thread->id }}" @class([
    'rounded-2xl border p-3 font-sans text-sm',
    'border-zinc-200/70 bg-white/80 dark:border-white/10 dark:bg-white/5' => !$thread->isResolved(),
    'border-dashed border-zinc-300/70 bg-zinc-900/5 opacity-70 dark:border-white/10 dark:bg-white/5' => $thread->isResolved(),
])>
    <div class="flex items-center justify-between gap-3 text-xs text-zinc-500 dark:text-zinc-400">
        <span>
            {{ $thread->kind === 'line' ? ($thread->side === 'old' ? 'Removed line ' . $thread->line : 'Line ' . $thread->line) : 'Pin' }}
            ·
            {{ $thread->isResolved() ? 'Resolved' . ($thread->resolver ? ' by ' . $thread->resolver->name : '') : 'Open' }}
        </span>

        @if ($canResolve)
            <button type="button" wire:click="toggleResolved({{ $thread->id }})" class="underline underline-offset-2">
                {{ $thread->isResolved() ? 'Reopen' : 'Resolve' }}
            </button>
        @endif
    </div>

    <ul class="mt-2 space-y-2">
        @foreach ($thread->comments as $comment)
            <li wire:key="thread-comment-{{ $comment->id }}">
                <p class="text-xs">
                    <span class="font-medium">{{ $comment->author?->name ?? 'Deleted user' }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400"> · {{ $comment->created_at->diffForHumans() }}</span>
                </p>
                <p class="whitespace-pre-line text-sm">{{ $comment->body }}</p>
            </li>
        @endforeach
    </ul>

    @if ($canDiscuss)
        <div class="mt-2">
            <button type="button" x-show="! open" x-on:click="open = true"
                class="text-xs text-zinc-500 underline underline-offset-2 dark:text-zinc-400">Reply</button>

            <div x-show="open" style="display: none" class="flex flex-col gap-2">
                <textarea x-model="reply" rows="2" maxlength="2000" placeholder="Write a reply…"
                    class="w-full rounded-xl border border-zinc-300/70 bg-white/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ink dark:border-white/15 dark:bg-white/10 dark:focus:ring-brand"></textarea>
                <div class="flex justify-end gap-2">
                    <button type="button" x-on:click="open = false; reply = ''"
                        class="rounded-full px-3 py-1 text-xs text-zinc-500">Cancel</button>
                    <button type="button"
                        x-on:click="if (reply.trim()) { $wire.replyThread({{ $thread->id }}, reply).then(() => { reply = ''; open = false }) }"
                        class="rounded-full bg-ink px-4 py-1 text-xs font-medium text-white dark:bg-brand dark:text-ink">
                        Reply
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>