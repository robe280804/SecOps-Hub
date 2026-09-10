<?php

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectEnvironmentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo environments may only be seeded in local or testing environments.');
        }

        $creator = User::query()->where('email', config('admin.email'))->first();

        if ($creator === null || ! $creator->hasRole(UserRole::Admin)) {
            throw new RuntimeException('Run AdminSeeder with a configured ADMIN_EMAIL before seeding demo environments.');
        }

        $image = config('environments.approved_images.0');

        if (! is_string($image) || $image === '') {
            throw new RuntimeException('Configure ENVIRONMENTS_APPROVED_IMAGES before seeding demo environments.');
        }

        DB::transaction(function () use ($creator, $image): void {
            $projects = $creator->projects()
                ->whereIn('name', array_map(fn (ProjectType $type): string => 'Demo: '.$type->value, ProjectType::cases()))
                ->orderBy('id')->lockForUpdate()->get();

            if ($projects->isEmpty()) {
                throw new RuntimeException('Run ProjectSeeder before seeding demo environments.');
            }

            foreach ($projects as $project) {
                if ($project->status === ProjectStatus::Archived
                    || $project->environments()->where('name', 'Demo environment')->exists()) {
                    continue;
                }

                if ($project->environments()->count() >= config('environments.max_per_project')) {
                    throw new RuntimeException('The environment limit for a demo project has been reached.');
                }

                $project->environments()->create([
                    'name' => 'Demo environment',
                    'description' => 'Local demonstration environment. No runtime has been provisioned.',
                    'base_image' => $image,
                    'network_configuration' => config('environments.default_network_configuration'),
                    'resource_limits' => config('environments.default_resource_limits'),
                ]);
            }
        }, 3);
    }
}
