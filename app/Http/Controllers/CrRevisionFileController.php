<?php

namespace App\Http\Controllers;

use App\Models\CrRevisionFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CrRevisionFileController
{
    public function download(CrRevisionFile $file): StreamedResponse
    {
        $this->authorizeView($file);

        return Storage::disk('local')->download($file->path, $file->original_name);
    }

    public function preview(CrRevisionFile $file): StreamedResponse
    {
        $this->authorizeView($file);

        abort_unless($file->isImage(), 404);

        return Storage::disk('local')->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function authorizeView(CrRevisionFile $file): void
    {
        $cr = $file->revision?->changeRequest ?? abort(404);

        Gate::authorize('view', $cr);
    }
}