@props(['title' => 'Drop files here or click to browse', 'hint' => '', 'compact' => false])

<div class="relative" x-data="{ over: false, uploading: false, progress: 0 }"
    x-on:livewire-upload-start="uploading = true; progress = 0"
    x-on:livewire-upload-progress="progress = $event.detail.progress" x-on:livewire-upload-finish="uploading = false"
    x-on:livewire-upload-error="uploading = false">
    <div class="glass flex flex-col items-center gap-2 rounded-card border-2 border-dashed px-6 {{ $compact ? 'py-4' : 'py-8' }} text-center transition"
        :class="over ? 'border-ink dark:border-brand' : 'border-zinc-300/70 dark:border-white/20'">
        <flux:icon name="arrow-up-tray" class="size-6 text-zinc-500 dark:text-zinc-400" />
        <span class="text-lg font-light">{{ $title }}</span>

        @if ($hint)
            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $hint }}</span>
        @endif

        <div x-show="uploading" style="display: none" class="mt-2 h-1.5 w-full max-w-xs rounded-full bg-black/10">
            <div class="h-1.5 rounded-full bg-ink dark:bg-brand" :style="`width: ${progress}%`"></div>
        </div>
    </div>

    <input type="file" multiple {{ $attributes->whereStartsWith('wire:model') }}
        class="absolute inset-0 size-full cursor-pointer opacity-0" x-on:dragover="over = true"
        x-on:dragleave="over = false" x-on:drop="over = false">
</div>