<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cr_revision_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cr_revision_id')->constrained('cr_revisions')->cascadeOnDelete();
            $table->foreignId('project_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->foreignId('base_version_id')->constrained('file_versions')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);

            $table->unique(['cr_revision_id', 'project_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_revision_files');
    }
};
