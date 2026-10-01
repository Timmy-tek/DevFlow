@props(['label'])

<span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full bg-white/60 px-2 py-0.5 text-xs text-ink/80') }}>
    <span class="size-1.5 rounded-full {{ $label->dotClass() }}"></span>
    {{ $label->name }}
</span>