<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ProjectEnvironment;
use Illuminate\Support\Facades\Gate;

class UpdateProjectEnvironmentRequest extends ProjectEnvironmentRequest
{
    public function authorize(): bool
    {
        $environment = $this->route('environment');

        if (! $environment instanceof ProjectEnvironment) {
            return false;
        }

        Gate::authorize('update', $environment);

        return true;
    }
}
