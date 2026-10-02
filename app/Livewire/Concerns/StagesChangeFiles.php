<?php

namespace App\Livewire\Concerns;

use App\Models\Project;
use App\Models\ProjectFile;
use Illuminate\Support\Arr;
use Livewire\WithFileUploads;

trait StagesChangeFiles
{
    use WithFileUploads;

    /** The raw file input. Every pick is moved into $staged. */
    public $uploads = [];

    /** Each item: ['upload' => TemporaryUploadedFile, 'target' => project_file_id or ''] */
    public array $staged = [];

    abstract protected function stagingProject(): Project;

    public function updatedUploads(): void
    {
        $files = $this->stagingProject()->files()->get()
            ->keyBy(fn(ProjectFile $file) => mb_strtolower($file->name));

        foreach (Arr::wrap($this->uploads) as $upload) {
            $match = $files->get(mb_strtolower($upload->getClientOriginalName()));

            $this->staged[] = [
                'upload' => $upload,
                'target' => $match ? (string) $match->id : '',
            ];
        }

        $this->uploads = [];
    }

    public function unstage(int $index): void
    {
        unset($this->staged[$index]);

        $this->staged = array_values($this->staged);
    }
}