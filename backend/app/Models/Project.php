<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'description', 'type', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'inactive'];

    protected function casts(): array
    {
        return ['type' => ProjectType::class, 'status' => ProjectStatus::class];
    }

    protected static function booted(): void
    {
        static::updating(function (Project $project): void {
            if ($project->isDirty('user_id')) {
                throw ValidationException::withMessages(['user_id' => ['Project ownership cannot be transferred.']]);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ProjectMembership::class);
    }
}
