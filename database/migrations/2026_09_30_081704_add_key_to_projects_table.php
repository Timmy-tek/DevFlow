<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('key', 6)->nullable()->after('slug');
        });

        DB::table('projects')->orderBy('id')->get()->each(function ($project) {
            DB::table('projects')->where('id', $project->id)->update([
                'key' => Project::makeKey($project->name),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }
};
