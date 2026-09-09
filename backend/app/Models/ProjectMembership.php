<?php

namespace App\Models;

use App\Enums\ProjectAccessLevel;
use Database\Factories\ProjectMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable(['access_level'])]
class ProjectMembership extends Model
{
    /** @use HasFactory<ProjectMembershipFactory> */
    use HasFactory;

    protected $attributes = ['access_level' => 'viewer'];

    protected function casts(): array
    {
        return ['access_level' => ProjectAccessLevel::class];
    }

    protected static function booted(): void
    {
        static::updating(function (ProjectMembership $membership): void {
            if ($membership->isDirty(['project_id', 'user_id'])) {
                throw ValidationException::withMessages([
                    'membership' => ['Membership identity cannot be changed. Revoke access and create a new membership instead.'],
                ]);
            }
        });

        static::saving(function (ProjectMembership $membership): void {
            if (Project::query()->whereKey($membership->project_id)->where('user_id', $membership->user_id)->exists()) {
                throw ValidationException::withMessages(['user_id' => ['The project creator cannot be added as a collaborator.']]);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
