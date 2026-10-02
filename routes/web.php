<?php

use App\Http\Middleware\EnsureUserHasWorkspace;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\FileVersionController;

use App\Http\Controllers\CrRevisionFileController;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('workspaces/create', 'pages::workspaces.create')->name('workspaces.create');
    Route::get('files/versions/{version}/download', [FileVersionController::class, 'download'])->name('files.download');
    Route::get('files/versions/{version}/preview', [FileVersionController::class, 'preview'])->name('files.preview');
    Route::get('change-request-files/{file}/download', [CrRevisionFileController::class, 'download'])->name('cr-files.download');
    Route::get('change-request-files/{file}/preview', [CrRevisionFileController::class, 'preview'])->name('cr-files.preview');
    Route::livewire('invitations/{token}', 'pages::invitations.accept')->name('invitations.accept');


    Route::middleware(EnsureUserHasWorkspace::class)->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('projects', 'pages::projects.index')->name('projects.index');
        Route::livewire('projects/{slug}', 'pages::projects.show')->name('projects.show');
        Route::livewire('projects/{slug}/board', 'pages::projects.board')->name('projects.board');
        Route::livewire('projects/{slug}/activity', 'pages::projects.activity')->name('projects.activity');
        Route::livewire('projects/{slug}/files', 'pages::projects.files')->name('projects.files');
        Route::livewire('projects/{slug}/changes', 'pages::changes.index')->name('changes.index');
        Route::livewire('projects/{slug}/changes/create', 'pages::changes.create')->name('changes.create');
        Route::livewire('projects/{slug}/changes/{number}', 'pages::changes.show')->whereNumber('number')->name('changes.show');
        Route::livewire('members', 'pages::members.index')->name('members.index');
    });
});

require __DIR__ . '/settings.php';