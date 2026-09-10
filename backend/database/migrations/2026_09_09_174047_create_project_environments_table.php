<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('project_environments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('base_image');
            $table->enum('desired_state', ['stopped', 'running', 'deleted'])->default('stopped');
            $table->enum('status', ['inactive', 'provisioning', 'stopped', 'starting', 'ready', 'stopping', 'error', 'deleting'])->default('inactive');
            $table->string('runtime_reference')->nullable();
            $table->unsignedBigInteger('runtime_generation')->default(0);
            $table->string('runtime_status')->nullable();
            $table->string('workspace_reference')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->json('network_configuration')->nullable();
            $table->json('resource_limits')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_environments');
    }
};
