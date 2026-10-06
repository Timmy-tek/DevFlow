<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->foreignId('release_id')->nullable()->after('task_id')->constrained('releases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('change_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('release_id');
        });
    }
};
