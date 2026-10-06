@props(['d', 'viewed' => false, 'canDiscuss' => false, 'canResolve' => false])

@php
    $rf = $d['rf'];
    $base = $d['base'];
    $fileId = $rf->project_file_id;
@endphp

<x-card x-data="{ viewed: {{ $viewed ? 'true' : 'false' }}, composing: null, draft: '' }" wire:key="diff-{{ $rf->id }}">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate font-medium">{{ $rf->file?->name ?? $rf->original_name }}</p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                v{{ $base->number }} → proposed in r{{ $rf->revision->number }}
                @if ($d['openThreads'] > 0)
                    · {{ $d['openThreads'] }} open {{ $d['openThreads'] === 1 ? 'thread' : 'threads' }}
                @endif
            </p>
        </div>

        <div class="flex items-center gap-4 text-xs">
            @if ($d['kind'] === 'text')
                <span class="text-emerald-600 dark:text-emerald-400">+{{ $d['added'] }}</span>
                <span class="text-rose-600 dark:text-rose-400">−{{ $d['removed'] }}</span>
            @endif
            <label class="flex cursor-pointer items-center gap-1.5 text-zinc-600 dark:text-zinc-300">
                <input type="checkbox" x-model="viewed" x-on:change="$wire.toggleViewed({{ $fileId }})"> Viewed
            </label>
        </div>
    </div>

    <div x-show="! viewed" class="mt-4">
        {{-- ───── Text: unified diff with line threads ───── --}}
        @if ($d['kind'] === 'text')
            <div class="overflow-x-auto rounded-xl border border-zinc-200/70 font-mono text-xs dark:border-white/10">
                @forelse ($d['rows'] as $row)
                    @if ($row['type'] === 'gap')
                        <div class="bg-zinc-900/5 px-3 py-1 text-center text-zinc-500 dark:bg-white/5 dark:text-zinc-400">{{ $row['text'] }}</div>
                    @else
                        @php
                            $side = $row['type'] === 'del' ? 'old' : 'new';
                            $lineNo = $row['type'] === 'del' ? $row['old'] : $row['new'];
                            $anchor = $side.':'.$lineNo;
                            $rowThreads = $d['lineThreads']->get($anchor, collect());
                        @endphp

                        <div class="group relative">
                            <div @class([
                                'grid grid-cols-[3rem_3rem_1.25rem_1fr]',
                                'bg-emerald-100/70 text-emerald-900 dark:bg-emerald-500/15 dark:text-emerald-200' => $row['type'] === 'add',
                                'bg-rose-100/80 text-rose-900 dark:bg-rose-500/15 dark:text-rose-200' => $row['type'] === 'del',
                            ])>
                                <span class="select-none px-2 text-end opacity-50">{{ $row['old'] }}</span>
                                <span class="select-none px-2 text-end opacity-50">{{ $row['new'] }}</span>
                                <span class="select-none">{{ $row['type'] === 'add' ? '+' : ($row['type'] === 'del' ? '−' : ' ') }}</span>
                                <span class="whitespace-pre-wrap break-all pe-3">{{ $row['text'] }}</span>
                            </div>

                            @if ($canDiscuss)
                                <button
                                    type="button"
                                    x-on:click="composing = '{{ $anchor }}'; draft = ''"
                                    class="absolute left-0.5 top-0.5 hidden size-5 place-items-center rounded bg-ink text-xs text-white group-hover:grid dark:bg-brand dark:text-ink"
                                    aria-label="Comment on this line"
                                >+</button>
                            @endif
                        </div>

                        @foreach ($rowThreads as $thread)
                            <div class="bg-white/60 px-3 py-2 font-sans text-sm dark:bg-white/5">
                                <x-thread :thread="$thread" :can-discuss="$canDiscuss" :can-resolve="$canResolve" />
                            </div>
                        @endforeach

                        @if ($canDiscuss)
                            <div x-show="composing === '{{ $anchor }}'" style="display: none" class="bg-white/60 px-3 py-2 font-sans text-sm dark:bg-white/5">
                                <textarea
                                    x-model="draft"
                                    rows="2"
                                    maxlength="2000"
                                    placeholder="Comment on this line…"
                                    class="w-full rounded-xl border border-zinc-300/70 bg-white/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ink dark:border-white/15 dark:bg-white/10 dark:focus:ring-brand"
                                ></textarea>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button type="button" x-on:click="composing = null" class="rounded-full px-3 py-1 text-xs text-zinc-500">Cancel</button>
                                    <button
                                        type="button"
                                        x-on:click="if (draft.trim()) { $wire.startLineThread({{ $fileId }}, '{{ $side }}', {{ $lineNo }}, draft).then(() => { composing = null; draft = '' }) }"
                                        class="rounded-full bg-ink px-4 py-1 text-xs font-medium text-white dark:bg-brand dark:text-ink"
                                    >
                                        Comment
                                    </button>
                                </div>
                            </div>
                        @endif
                    @endif
                @empty
                    <div class="px-3 py-4 text-center text-zinc-500 dark:text-zinc-400">No textual changes (whitespace or line endings only).</div>
                @endforelse
            </div>

        {{-- ───── Image: before/after slider with pin comments ───── --}}
        @elseif ($d['kind'] === 'image')
            <div x-data="{ pos: 50, pinning: false, pin: null, draftPin: '' }" class="space-y-3">
                @if ($canDiscuss)
                    <div class="flex justify-end">
                        <button
                            type="button"
                            x-on:click="pinning = ! pinning; pin = null"
                            x-text="pinning ? 'Cancel pin' : 'Drop a pin'"
                            class="rounded-full border border-zinc-300/70 px-4 py-1.5 text-xs dark:border-white/15"
                        >Drop a pin</button>
                    </div>
                @endif

                <div class="relative mx-auto w-fit max-w-full overflow-hidden rounded-xl bg-zinc-900/5 dark:bg-white/5">
                    <img src="{{ route('cr-files.preview', $rf) }}" alt="Proposed version" class="block max-h-[28rem] w-auto max-w-full">
                    <img
                        src="{{ route('files.preview', $base) }}"
                        alt="Current version"
                        class="absolute inset-0 size-full object-contain"
                        :style="`clip-path: inset(0 ${100 - pos}% 0 0)`"
                    >
                    <div class="pointer-events-none absolute inset-y-0 w-0.5 bg-brand shadow" :style="`left: ${pos}%`"></div>

                    @foreach ($d['pins'] as $i => $thread)
                        <span
                            class="pointer-events-none absolute grid size-6 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full text-xs font-medium shadow {{ $thread->isResolved() ? 'bg-zinc-400 text-white' : 'bg-brand text-ink' }}"
                            style="left: {{ $thread->pin_x }}%; top: {{ $thread->pin_y }}%"
                        >{{ $i + 1 }}</span>
                    @endforeach

                    <template x-if="pin">
                        <span class="pointer-events-none absolute size-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-ink bg-white/70" :style="`left: ${pin.x}%; top: ${pin.y}%`"></span>
                    </template>

                    <div
                        x-show="pinning"
                        style="display: none"
                        class="absolute inset-0 cursor-crosshair"
                        x-on:click="const r = $el.getBoundingClientRect(); pin = { x: +((($event.clientX - r.left) / r.width * 100).toFixed(2)), y: +((($event.clientY - r.top) / r.height * 100).toFixed(2)) }"
                    ></div>
                </div>

                <input type="range" min="0" max="100" x-model="pos" class="w-full accent-ink dark:accent-brand" aria-label="Compare current and proposed">
                <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                    <span>Current · v{{ $base->number }}</span>
                    <span>Proposed · r{{ $rf->revision->number }}</span>
                </div>

                <div x-show="pin" style="display: none" class="rounded-2xl border border-zinc-200/70 bg-white/80 p-3 dark:border-white/10 dark:bg-white/5">
                    <textarea
                        x-model="draftPin"
                        rows="2"
                        maxlength="2000"
                        placeholder="Comment on this spot…"
                        class="w-full rounded-xl border border-zinc-300/70 bg-white/80 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-ink dark:border-white/15 dark:bg-white/10 dark:focus:ring-brand"
                    ></textarea>
                    <div class="mt-2 flex justify-end gap-2">
                        <button type="button" x-on:click="pin = null; pinning = false" class="rounded-full px-3 py-1 text-xs text-zinc-500">Cancel</button>
                        <button
                            type="button"
                            x-on:click="if (draftPin.trim()) { $wire.startPinThread({{ $fileId }}, pin.x, pin.y, draftPin).then(() => { pin = null; pinning = false; draftPin = '' }) }"
                            class="rounded-full bg-ink px-4 py-1 text-xs font-medium text-white dark:bg-brand dark:text-ink"
                        >
                            Comment
                        </button>
                    </div>
                </div>

                @foreach ($d['pins'] as $i => $thread)
                    <div class="flex gap-3">
                        <span class="grid size-6 shrink-0 place-items-center rounded-full text-xs font-medium {{ $thread->isResolved() ? 'bg-zinc-400 text-white' : 'bg-brand text-ink' }}">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <x-thread :thread="$thread" :can-discuss="$canDiscuss" :can-resolve="$canResolve" />
                        </div>
                    </div>
                @endforeach
            </div>

        {{-- ───── Anything else ───── --}}
        @else
            <div class="grid gap-3 text-sm sm:grid-cols-2">
                <div class="rounded-xl bg-zinc-900/5 p-3 dark:bg-white/5">
                    <p class="font-medium">Current · v{{ $base->number }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $base->humanSize() }} · {{ $base->shortHash() }}</p>
                    <a href="{{ route('files.download', $base) }}" class="mt-2 inline-block text-xs underline">Download</a>
                </div>
                <div class="rounded-xl bg-zinc-900/5 p-3 dark:bg-white/5">
                    <p class="font-medium">Proposed · r{{ $rf->revision->number }}</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $rf->humanSize() }} · {{ $rf->shortHash() }}</p>
                    <a href="{{ route('cr-files.download', $rf) }}" class="mt-2 inline-block text-xs underline">Download</a>
                </div>
            </div>
            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">No inline diff available for this file (binary or too large).</p>
        @endif

        {{-- ───── Threads made on earlier revisions of this file ───── --}}
        @if ($d['outdated']->isNotEmpty())
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">
                    {{ $d['outdated']->count() }} outdated {{ $d['outdated']->count() === 1 ? 'thread' : 'threads' }} on earlier revisions
                </summary>
                <div class="mt-2 space-y-2">
                    @foreach ($d['outdated'] as $thread)
                        <x-thread :thread="$thread" :can-discuss="$canDiscuss" :can-resolve="$canResolve" />
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</x-card>