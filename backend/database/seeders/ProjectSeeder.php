<?php

namespace Database\Seeders;

use App\Models\User;
use App\ProjectStatus;
use App\ProjectType;
use App\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo projects may only be seeded in local or testing environments.');
        }

        $creator = User::query()->where('email', config('admin.email'))->first();

        if ($creator === null || ! $creator->hasRole(UserRole::Admin)) {
            throw new RuntimeException('Run AdminSeeder with a configured ADMIN_EMAIL before seeding demo projects.');
        }

        DB::transaction(function () use ($creator): void {
            foreach (ProjectType::cases() as $type) {
                $creator->projects()->firstOrCreate([
                    'name' => 'Demo: '.$type->value,
                ], [
                    'description' => 'Local demonstration project.',
                    'type' => $type,
                    'status' => ProjectStatus::Inactive,
                ]);
            }
        });
    }
}
