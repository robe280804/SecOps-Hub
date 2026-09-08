<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\ProjectStatus;
use App\ProjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'type' => ProjectType::Personal,
            'status' => ProjectStatus::Inactive,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ProjectStatus::Active]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ProjectStatus::Archived]);
    }
}
