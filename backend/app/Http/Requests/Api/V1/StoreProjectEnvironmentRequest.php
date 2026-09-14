<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Project;
use App\Models\ProjectEnvironment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class StoreProjectEnvironmentRequest extends ProjectEnvironmentRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        Gate::authorize('create', [ProjectEnvironment::class, $project]);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'network_configuration' => config('environments.default_network_configuration'),
            'egress_configuration' => config('environments.egress.default'),
            'resource_limits' => config('environments.default_resource_limits'),
        ]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['required', 'string', 'max:255'];
        array_shift($rules['base_image']);

        return $rules;
    }
}
