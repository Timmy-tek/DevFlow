@props(['tone' => 'glass'])

@php
    $tones = [
        'glass' => 'glass',
        'dark' => 'bg-ink text-white',
        'lavender' => 'bg-lavender text-ink',
        'peach' => 'bg-peach text-ink',
        'mint' => 'bg-mint text-ink',
        'sky' => 'bg-sky text-ink',
        'rose' => 'bg-rose text-ink',
        'butter' => 'bg-butter text-ink',
    ];
@endphp

<div {{ $attributes->class(['rounded-card p-5', $tones[$tone] ?? $tones['glass']]) }}>
    {{ $slot }}
</div>