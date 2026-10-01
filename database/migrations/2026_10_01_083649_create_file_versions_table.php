<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('file_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('change_request_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_file_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_versions');
    }
};
