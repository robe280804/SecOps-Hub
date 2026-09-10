<?php

namespace App\Models;

use App\Enums\EnvironmentDesiredState;
use App\Enums\EnvironmentStatus;
use Database\Factories\ProjectEnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'description', 'base_image', 'network_configuration', 'resource_limits'])]
class ProjectEnvironment extends Model
{
    /** @use HasFactory<ProjectEnvironmentFactory> */
    use HasFactory;

    protected $attributes = [
        'desired_state' => 'stopped',
        'status' => 'inactive',
        'runtime_generation' => 0,
    ];

    protected $hidden = ['runtime_reference', 'workspace_reference', 'last_error'];

    protected function casts(): array
    {
        return [
            'desired_state' => EnvironmentDesiredState::class,
            'status' => EnvironmentStatus::class,
            'runtime_generation' => 'integer',
            'network_configuration' => 'array',
            'resource_limits' => 'array',
            'last_observed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProjectEnvironment $environment): void {
            if ($environment->isDirty('project_id')) {
                throw ValidationException::withMessages([
                    'project_id' => ['An environment cannot be moved to another project.'],
                ]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isUnprovisioned(): bool
    {
        return $this->status === EnvironmentStatus::Inactive
            && $this->desired_state === EnvironmentDesiredState::Stopped
            && $this->runtime_generation === 0
            && $this->runtime_reference === null
            && $this->runtime_status === null
            && $this->workspace_reference === null;
    }
}
