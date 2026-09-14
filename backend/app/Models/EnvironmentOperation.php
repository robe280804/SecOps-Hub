<?php

namespace App\Models;

use Database\Factories\EnvironmentOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvironmentOperation extends Model
{
    /** @use HasFactory<EnvironmentOperationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'completed_at' => 'immutable_datetime'];
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(ProjectEnvironment::class, 'project_environment_id');
    }
}
