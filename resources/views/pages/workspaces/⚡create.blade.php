<?php

use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Create workspace')]
    #[Layout('layouts::auth')]
    class extends Component {
    public string $name = '';

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
        ]);

        $workspace = Workspace::create([
            'name' => $this->name,
            'owner_id' => Auth::id(),
        ]);

        Auth::user()->switchWorkspace($workspace);

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create your workspace')" :description="__('A workspace is where your team, projects and files live. You can create more later.')" />

    <form wire:submit="create" class="flex flex-col gap-6">
        <flux:input wire:model="name" :label="__('Workspace name')" placeholder="Timmy Studio" required autofocus />

        <flux:button type="submit" variant="primary" class="w-full">
            {{ __('Create workspace') }}
        </flux:button>
    </form>
</div>