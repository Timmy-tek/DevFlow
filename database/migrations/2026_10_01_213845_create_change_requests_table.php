<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('sync_task')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
