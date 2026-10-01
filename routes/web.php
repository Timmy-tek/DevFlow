<?php

use App\Http\Middleware\EnsureUserHasWorkspace;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\FileVersionController;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('workspaces/create', 'pages::workspaces.create')->name('workspaces.create');
    Route::get('files/versions/{version}/download', [FileVersionController::class, 'download'])->name('files.download');
    Route::get('files/versions/{version}/preview', [FileVersionController::class, 'preview'])->name('files.preview');
    Route::livewire('invitations/{token}', 'pages::invitations.accept')->name('invitations.accept');


    Route::middleware(EnsureUserHasWorkspace::class)->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/{slug}', 'pages::projects.show')->name('projects.show');
        Route::livewire('projects/{slug}/board', 'pages::projects.board')->name('projects.board');
        Route::livewire('projects/{slug}/activity', 'pages::projects.activity')->name('projects.activity');
        Route::livewire('projects/{slug}/files', 'pages::projects.files')->name('projects.files');
        Route::livewire('members', 'pages::members.index')->name('members.index');
    });
});

require __DIR__ . '/settings.php';