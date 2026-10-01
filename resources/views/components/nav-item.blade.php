@props(['href' => '#', 'icon', 'active' => false, 'soon' => false])

<a href="{{ $href }}" @if (!$soon) wire:navigate @endif {{ $attributes->class([
    'flex items-center gap-3 rounded-full px-4 py-2.5 text-sm font-medium transition',
    'bg-ink text-white shadow-sm dark:bg-brand dark:text-ink' => $active,
    'text-zinc-600 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-white/10' => !$active && !$soon,
    'cursor-default text-zinc-400 dark:text-zinc-600' => $soon,
]) }}>
    <flux:icon :name="$icon" class="size-5" />
    <span>{{ $slot }}</span>
    @if ($soon)
        <span
            class="ms-auto rounded-full bg-zinc-900/5 px-2 py-0.5 text-[10px] uppercase tracking-wide dark:bg-white/10">soon</span>
    @endif
</a>