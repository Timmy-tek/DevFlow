<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cr_file_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->foreignId('cr_revision_file_id')->constrained('cr_revision_files')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['change_request_id', 'user_id', 'project_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_file_views');
    }
};
