<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cr_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cr_revision_id')->constrained('cr_revisions')->cascadeOnDelete();
            $table->string('state', 24);
            $table->text('body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['change_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cr_reviews');
    }
};
