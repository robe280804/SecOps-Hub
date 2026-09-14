<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->json('egress_configuration')->nullable()->after('network_configuration');
        });

        DB::table('project_environments')->whereNull('egress_configuration')->update([
            'egress_configuration' => json_encode(config('environments.egress.default')),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_environments', function (Blueprint $table) {
            $table->dropColumn('egress_configuration');
        });
    }
};
