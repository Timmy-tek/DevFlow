@props(['href' => '#', 'active' => false, 'soon' => false])

<a @if (!$soon) href="{{ $href }}" wire:navigate @endif {{ $attributes->class([
    'whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition',
    'bg-ink text-white dark:bg-brand dark:text-ink' => $active,
    'text-zinc-600 hover:bg-white/70 dark:text-zinc-300 dark:hover:bg-white/10' => !$active && !$soon,
    'cursor-default text-zinc-400 dark:text-zinc-600' => $soon,
]) }}>
    {{ $slot }}
</a>