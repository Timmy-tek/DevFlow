<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->foreignId('task_id')->nullable()->after('uploaded_by')->constrained('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('file_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_id');
        });
    }
};
