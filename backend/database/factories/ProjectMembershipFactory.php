<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\ProjectAccessLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectMembership>
 */
class ProjectMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'access_level' => ProjectAccessLevel::Viewer,
        ];
    }

    public function contributor(): static
    {
        return $this->state(fn (array $attributes): array => ['access_level' => ProjectAccessLevel::Contributor]);
    }
}
