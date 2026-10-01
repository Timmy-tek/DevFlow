<?php

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Members')]
    class extends Component {
    public string $email = '';
    public string $role = 'developer';

    #[Computed]
    public function workspace(): Workspace
    {
        return Auth::user()->currentWorkspace;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage', $this->workspace);
    }

    #[Computed]
    public function members()
    {
        return $this->workspace->members()->orderBy('name')->get();
    }

    #[Computed]
    public function invitations()
    {
        return $this->workspace->invitations()->pending()->latest()->get();
    }

    #[Computed]
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            WorkspaceRole::cases(),
            fn(WorkspaceRole $r) => $r !== WorkspaceRole::Owner,
        ));
    }

    protected function assignableValues(): array
    {
        return array_map(fn(WorkspaceRole $r) => $r->value, $this->assignableRoles);
    }

    public function invite(): void
    {
        Gate::authorize('manage', $this->workspace);

        $validated = $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->assignableValues())],
        ]);

        $email = Str::lower($validated['email']);

        if ($this->workspace->members()->where('users.email', $email)->exists()) {
            $this->addError('email', 'That person is already a member.');

            return;
        }

        // One live invitation per email: replace any older one.
        $this->workspace->invitations()->pending()->where('email', $email)->delete();

        $this->workspace->invitations()->create([
            'email' => $email,
            'role' => $validated['role'],
            'token' => Str::random(48),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->reset('email');
        $this->role = 'developer';
        unset($this->invitations);

        Flux::toast(variant: 'success', text: 'Invitation created. Copy the link below and send it.');
    }

    public function changeRole(int $userId, string $role): void
    {
        Gate::authorize('manage', $this->workspace);

        abort_unless(in_array($role, $this->assignableValues(), true), 422);
        abort_if($userId === $this->workspace->owner_id, 403);

        $this->workspace->members()->updateExistingPivot($userId, ['role' => $role]);

        unset($this->members);
        Flux::toast(variant: 'success', text: 'Role updated.');
    }

    public function removeMember(int $userId): void
    {
        Gate::authorize('manage', $this->workspace);

        abort_if($userId === $this->workspace->owner_id, 403);

        $this->workspace->members()->detach($userId);

        unset($this->members);
        Flux::toast(variant: 'success', text: 'Member removed.');
    }

    public function revoke(int $invitationId): void
    {
        Gate::authorize('manage', $this->workspace);

        $this->workspace->invitations()->whereKey($invitationId)->delete();

        unset($this->invitations);
    }
}; ?>

@php
    $selectClasses = 'rounded-full border border-zinc-300/70 bg-white/70 px-4 py-2 text-sm dark:border-white/15 dark:bg-white/10';
@endphp

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6">
    <div>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->workspace->name }}</p>
        <h1 class="text-4xl font-light tracking-tight sm:text-5xl">Members</h1>
    </div>

    @if ($this->canManage)
        <x-card>
            <h2 class="text-lg font-medium">Invite someone</h2>
            <form wire:submit="invite" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex-1">
                    <flux:input wire:model="email" type="email" placeholder="teammate@example.com" required />
                </div>
                <select wire:model="role" class="{{ $selectClasses }}">
                    @foreach ($this->assignableRoles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                <flux:button type="submit" variant="primary" class="!rounded-full">Send invite</flux:button>
            </form>
        </x-card>
    @endif

    <x-card>
        <h2 class="text-lg font-medium">
            Team <span class="text-zinc-500 dark:text-zinc-400">({{ $this->members->count() }})</span>
        </h2>

        <ul class="mt-4 grid gap-2">
            @foreach ($this->members as $member)
                <li wire:key="member-{{ $member->id }}"
                    class="flex items-center gap-3 rounded-2xl bg-white/70 px-4 py-3 dark:bg-white/10">
                    <flux:avatar :name="$member->name" :initials="$member->initials()" size="sm" />

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $member->name }}</div>
                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $member->email }}</div>
                    </div>

                    @if ($this->canManage && $member->id !== $this->workspace->owner_id)
                        <select wire:change="changeRole({{ $member->id }}, $event.target.value)" class="{{ $selectClasses }}">
                            @foreach ($this->assignableRoles as $r)
                                <option value="{{ $r->value }}" @selected($member->pivot->role === $r->value)>{{ $r->label() }}
                                </option>
                            @endforeach
                        </select>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeMember({{ $member->id }})"
                            wire:confirm="Remove {{ $member->name }} from this workspace?" />
                    @else
                        <span class="rounded-full bg-zinc-900/5 px-3 py-1 text-xs dark:bg-white/10">
                            {{ \App\Enums\WorkspaceRole::from($member->pivot->role)->label() }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-card>

    @if ($this->canManage && $this->invitations->isNotEmpty())
        <x-card>
            <h2 class="text-lg font-medium">Pending invitations</h2>

            <ul class="mt-4 grid gap-2">
                @foreach ($this->invitations as $invitation)
                    @php $url = route('invitations.accept', $invitation->token); @endphp
                    <li wire:key="invite-{{ $invitation->id }}"
                        class="flex items-center gap-3 rounded-2xl bg-white/70 px-4 py-3 dark:bg-white/10">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ $invitation->email }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $invitation->role->label() }} · expires {{ $invitation->expires_at->diffForHumans() }}
                            </div>
                        </div>

                        <button type="button" x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1500)"
                            x-text="copied ? 'Copied ✓' : 'Copy link'"
                            class="rounded-full bg-ink px-4 py-1.5 text-xs font-medium text-white dark:bg-brand dark:text-ink">Copy
                            link</button>

                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="revoke({{ $invitation->id }})"
                            wire:confirm="Revoke this invitation?" />
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</section>