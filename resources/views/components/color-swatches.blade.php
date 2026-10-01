@php
    $swatches = [
        'lavender' => 'bg-lavender',
        'peach' => 'bg-peach',
        'mint' => 'bg-mint',
        'sky' => 'bg-sky',
        'rose' => 'bg-rose',
        'butter' => 'bg-butter',
    ];
@endphp

<div class="flex gap-3">
    @foreach ($swatches as $value => $class)
        <label class="cursor-pointer" wire:key="swatch-{{ $value }}">
            <input type="radio" value="{{ $value }}" {{ $attributes->whereStartsWith('wire:model') }} class="peer sr-only">
            <span
                class="block size-9 rounded-full {{ $class }} ring-offset-2 peer-checked:ring-2 peer-checked:ring-ink dark:ring-offset-zinc-900 dark:peer-checked:ring-brand"></span>
        </label>
    @endforeach
</div>