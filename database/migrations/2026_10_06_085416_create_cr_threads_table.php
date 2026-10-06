<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cr_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_file_id')->constrained('project_files')->cascadeOnDelete();
            $table->foreignId('cr_revision_file_id')->constrained('cr_revision_files')->cascadeOnDelete();
            $table->string('kind', 8);                    // line | pin
            $table->string('side', 3)->nullable();        // old | new (line threads)
            $table->unsignedInteger('line')->nullable();  // line threads
            $table->decimal('pin_x', 5, 2)->nullable();   // pin threads: % of image width
            $table->decimal('pin_y', 5, 2)->nullable();   // pin threads: % of image height
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['change_request_id', 'project_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_threads');
    }
};
