<?php

namespace App\Http\Controllers;

use App\Models\FileVersion;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileVersionController
{
    public function download(FileVersion $version): StreamedResponse
    {
        $this->authorizeView($version);

        return Storage::disk('local')->download($version->path, $version->original_name);
    }

    public function preview(FileVersion $version): StreamedResponse
    {
        $this->authorizeView($version);

        abort_unless($version->isImage(), 404);

        return Storage::disk('local')->response($version->path, $version->original_name, [
            'Content-Type' => $version->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function authorizeView(FileVersion $version): void
    {
        $file = $version->file ?? abort(404);

        Gate::authorize('view', $file);
    }
}