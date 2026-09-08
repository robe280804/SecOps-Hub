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
        $timestamp = now();

        DB::table('roles')->insertOrIgnore([
            ['name' => 'admin', 'guard_name' => 'web', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'user', 'guard_name' => 'web', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ]);

        $roleIds = DB::table('roles')
            ->where('guard_name', 'web')
            ->whereIn('name', ['admin', 'user'])
            ->pluck('id', 'name');

        DB::table('users')
            ->select(['id', 'role'])
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($roleIds): void {
                $assignments = $users->map(fn ($user): array => [
                    'role_id' => $roleIds[$user->role === 'admin' ? 'admin' : 'user'],
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $user->id,
                ])->all();

                DB::table('model_has_roles')->insertOrIgnore($assignments);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member')->after('password');
        });

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($adminRoleId !== null) {
            $adminIds = DB::table('model_has_roles')
                ->where('role_id', $adminRoleId)
                ->where('model_type', 'App\\Models\\User')
                ->pluck('model_id');

            DB::table('users')->whereIn('id', $adminIds)->update(['role' => 'admin']);
        }
    }
};
