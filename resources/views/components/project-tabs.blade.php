@props(['project', 'active'])

<x-pill-tabs>
    <x-pill-tab :href="route('projects.show', $project->slug)" :active="$active === 'overview'">Overview</x-pill-tab>
    <x-pill-tab :href="route('projects.board', $project->slug)" :active="$active === 'board'">Board</x-pill-tab>
    <x-pill-tab :href="route('projects.files', $project->slug)" :active="$active === 'files'">Files</x-pill-tab>
    <x-pill-tab :href="route('changes.index', $project->slug)" :active="$active === 'changes'">Change
        requests</x-pill-tab>

    <x-pill-tab :href="route('releases.index', $project->slug)" :active="$active === 'releases'">Releases</x-pill-tab>
    <x-pill-tab :href="route('projects.activity', $project->slug)" :active="$active === 'activity'">Activity</x-pill-tab>
</x-pill-tabs>