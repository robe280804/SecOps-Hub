<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProjectAccessLevel;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateProjectMembershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        if (! $project instanceof Project) {
            return false;
        }

        Gate::authorize('manageMembers', $project);

        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'access_level' => ['required', Rule::enum(ProjectAccessLevel::class)],
            'user_id' => ['missing'],
            'project_id' => ['missing'],
        ];
    }
}
