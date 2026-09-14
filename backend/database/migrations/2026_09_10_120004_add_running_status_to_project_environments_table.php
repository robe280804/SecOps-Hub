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
            $table->enum('status', ['inactive', 'provisioning', 'stopped', 'starting', 'running', 'ready', 'stopping', 'error', 'deleting'])->default('inactive')->change();
            $table->enum('desired_state', ['stopped', 'running', 'deleted'])->default('stopped')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('project_environments')->where('status', 'running')->update(['status' => 'starting']);
        Schema::table('project_environments', function (Blueprint $table) {
            $table->enum('status', ['inactive', 'provisioning', 'stopped', 'starting', 'ready', 'stopping', 'error', 'deleting'])->default('inactive')->change();
            $table->enum('desired_state', ['stopped', 'running', 'deleted'])->default('stopped')->change();
        });
    }
};
