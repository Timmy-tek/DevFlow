<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-canvas bg-fixed text-ink dark:text-white">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-white/60 bg-white/50 backdrop-blur-xl dark:border-white/10 dark:bg-white/5">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>
        <div class="mt-3">
            <livewire:workspace-switcher />
        </div>

        <nav class="mt-2 grid gap-1">
            <x-nav-item icon="home" :href="route('dashboard')"
                :active="request()->routeIs('dashboard')">Dashboard</x-nav-item>
            <x-nav-item icon="folder" :href="route('projects.index')"
                :active="request()->routeIs('projects.*')">Projects</x-nav-item>
            <x-nav-item icon="users" :href="route('members.index')"
                :active="request()->routeIs('members.*')">Members</x-nav-item>
            <x-nav-item icon="check-circle" soon>My tasks</x-nav-item>
            <x-nav-item icon="arrow-path" soon>Change requests</x-nav-item>
            <x-nav-item icon="bolt" soon>Activity</x-nav-item>
        </nav>

        <flux:spacer />

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
    <flux:toast.group>
        <flux:toast />
    </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>