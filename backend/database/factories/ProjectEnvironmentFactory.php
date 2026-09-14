<?php

namespace Database\Factories;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectEnvironment> */
class ProjectEnvironmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->bothify('Environment-????-########'),
            'description' => fake()->sentence(),
            'base_image' => 'registry.example.test/secops@sha256:'.str_repeat('a', 64),
            'desired_state' => EnvironmentDesiredState::Stopped,
            'status' => EnvironmentStatus::Inactive,
            'runtime_generation' => 0,
            'network_configuration' => ['version' => 1, 'mode' => 'automatic', 'dns_servers' => [], 'search_domains' => []],
            'egress_configuration' => ['version' => 1, 'policy' => 'blocked', 'allowed_targets' => [], 'raw_sockets' => false],
            'resource_limits' => ['version' => 1, 'cpus' => 1, 'memory_bytes' => 536870912, 'storage_bytes' => 5368709120, 'pids' => 128],
        ];
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'desired_state' => EnvironmentDesiredState::Running,
            'status' => EnvironmentStatus::Provisioning,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'desired_state' => EnvironmentDesiredState::Running,
            'status' => EnvironmentStatus::Ready,
            'runtime_generation' => 1,
            'runtime_reference' => fake()->uuid(),
            'runtime_status' => 'running',
            'workspace_reference' => 'workspace-'.fake()->uuid(),
            'last_observed_at' => now(),
        ]);
    }

    public function stopped(): static
    {
        return $this->ready()->state(fn (array $attributes): array => [
            'desired_state' => EnvironmentDesiredState::Stopped,
            'status' => EnvironmentStatus::Stopped,
            'runtime_status' => 'exited',
        ]);
    }

    public function deleting(): static
    {
        return $this->stopped()->state(fn (array $attributes): array => [
            'desired_state' => EnvironmentDesiredState::Deleted,
            'status' => EnvironmentStatus::Deleting,
        ]);
    }
}
