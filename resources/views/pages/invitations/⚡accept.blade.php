<?php

use App\Models\WorkspaceInvitation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Join workspace')]
    #[Layout('layouts::auth')]
    class extends Component {
    public string $token;

    public function mount(string $token): void
    {
        $this->token = $token;

        abort_unless($this->invitation?->workspace, 404);
    }

    #[Computed]
    public function invitation(): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::with(['workspace', 'inviter'])
            ->where('token', $this->token)
            ->first();
    }

    #[Computed]
    public function problem(): ?string
    {
        $invitation = $this->invitation;
        $user = Auth::user();

        return match (true) {
            $invitation->accepted_at !== null => 'This invitation has already been used.',
            $invitation->expires_at->isPast() => 'This invitation has expired. Ask for a new one.',
            strcasecmp($invitation->email, $user->email) !== 0 => "This invitation was sent to {$invitation->email}, but you're signed in as {$user->email}.",
            default => null,
        };
    }

    public function accept(): void
    {
        abort_if($this->problem, 403);

        $invitation = $this->invitation;
        $workspace = $invitation->workspace;
        $user = Auth::user();

        // Never touch the role of someone who is already a member.
        if (!$workspace->members()->where('users.id', $user->id)->exists()) {
            $workspace->members()->attach($user->id, ['role' => $invitation->role->value]);
        }

        $invitation->update(['accepted_at' => now()]);
        $user->switchWorkspace($workspace);

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="'Join ' . $this->invitation->workspace->name" :description="($this->invitation->inviter?->name ?? 'Someone') . ' invited you as ' . $this->invitation->role->label() . '.'" />

    @if ($this->problem)
        <x-card tone="rose" class="text-sm">{{ $this->problem }}</x-card>

        <flux:button :href="route('dashboard')" wire:navigate class="w-full">Go to dashboard</flux:button>
    @else
        <flux:button wire:click="accept" variant="primary" class="w-full">Accept invitation</flux:button>
    @endif
</div>