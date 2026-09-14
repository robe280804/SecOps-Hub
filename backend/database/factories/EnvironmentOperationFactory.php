<?php

namespace Database\Factories;

use App\Models\EnvironmentOperation;
use App\Models\ProjectEnvironment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvironmentOperation>
 */
class EnvironmentOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_environment_id' => ProjectEnvironment::factory(),
            'requested_by' => User::factory(),
            'action' => 'start',
            'status' => 'pending',
            'attempts' => 0,
        ];
    }
}
