@props(['staged', 'files'])

@if (count($staged))
    <ul class="grid gap-2">
        @foreach ($staged as $i => $item)
            <li wire:key="staged-{{ $item['upload']->getFilename() }}"
                class="flex flex-wrap items-center gap-3 rounded-2xl bg-white/70 px-4 py-3 text-sm dark:bg-white/10">
                <span class="min-w-0 flex-1 truncate font-medium">{{ $item['upload']->getClientOriginalName() }}</span>
                <span class="text-zinc-500 dark:text-zinc-400">replaces</span>

                <select wire:model="staged.{{ $i }}.target"
                    class="rounded-full border border-zinc-300/70 bg-white/70 px-3 py-1.5 text-sm dark:border-white/15 dark:bg-white/10">
                    <option value="">Choose a file…</option>
                    @foreach ($files as $f)
                        <option value="{{ $f->id }}">{{ $f->name }}</option>
                    @endforeach
                </select>

                <button type="button" wire:click="unstage({{ $i }})" class="text-zinc-400 hover:text-red-500"
                    aria-label="Remove">
                    <flux:icon name="x-mark" class="size-4" />
                </button>
            </li>
        @endforeach
    </ul>
@endif